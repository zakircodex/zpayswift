package com.zworker.app

import android.annotation.SuppressLint
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.Service
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.net.Uri
import android.os.Build
import android.os.Handler
import android.os.IBinder
import android.os.Looper
import androidx.core.app.NotificationCompat
import java.text.DecimalFormat
import java.util.concurrent.Executors

class WorkerForegroundService : Service() {

    private lateinit var prefs: Prefs
    private lateinit var api: ApiClient

    private val mainHandler = Handler(Looper.getMainLooper())
    private val io = Executors.newSingleThreadExecutor()

    @Volatile
    private var started = false

    @Volatile
    private var busy = false

    private val pollRunnable = object : Runnable {
        override fun run() {
            if (!started) return

            io.execute {
                try {
                    runWorkerTick()
                } catch (e: Exception) {
                    log("Worker tick error: ${e.message}")
                }
            }

            mainHandler.postDelayed(this, 4000L)
        }
    }

    private val timeoutRunnable = Runnable {
        val requestId = prefs.activeRequestId
        if (requestId.isNotBlank() && busy) {
            io.execute {
                val ok = api.sendResult(
                    requestId = requestId,
                    resultStatus = "FAILED",
                    resultMessage = "USSD response timeout",
                    rawResponse = "Timed out waiting for response"
                )
                log("Timeout result sent: $ok")
                busy = false
                prefs.clearActiveRequest()
                updateNotification("Idle")
            }
        }
    }

    private val resultReceiver = object : BroadcastReceiver() {
        override fun onReceive(context: Context?, intent: Intent?) {
            if (intent?.action != WorkerConstants.ACTION_USSD_RESULT) return

            val requestId = prefs.activeRequestId
            if (requestId.isBlank()) return

            val resultStatus = intent.getStringExtra(WorkerConstants.EXTRA_RESULT_STATUS) ?: "FAILED"
            val resultMessage = intent.getStringExtra(WorkerConstants.EXTRA_RESULT_MESSAGE) ?: "Unknown result"
            val raw = intent.getStringExtra(WorkerConstants.EXTRA_RESULT_RAW) ?: ""

            mainHandler.removeCallbacks(timeoutRunnable)

            io.execute {
                val ok = api.sendResult(
                    requestId = requestId,
                    resultStatus = resultStatus,
                    resultMessage = resultMessage,
                    rawResponse = raw
                )
                log("Worker result sent: $resultStatus / ok=$ok")
                busy = false
                prefs.clearActiveRequest()
                updateNotification("Idle")
            }
        }
    }

    override fun onCreate() {
        super.onCreate()
        prefs = Prefs(this)
        api = ApiClient(prefs)
        registerResultReceiver()
    }

    override fun onDestroy() {
        started = false
        mainHandler.removeCallbacksAndMessages(null)
        unregisterReceiver(resultReceiver)
        io.shutdownNow()
        super.onDestroy()
    }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        when (intent?.action) {
            WorkerConstants.ACTION_STOP_WORKER -> {
                started = false
                stopForeground(STOP_FOREGROUND_REMOVE)
                stopSelf()
                return START_NOT_STICKY
            }

            else -> {
                startAsForeground()
                if (!started) {
                    started = true
                    log("Worker started")
                    mainHandler.post(pollRunnable)
                }
            }
        }
        return START_STICKY
    }

    private fun runWorkerTick() {
        val cfg = prefs.getConfig()
        if (cfg.baseUrl.isBlank() || cfg.workerKey.isBlank() || cfg.deviceId.isBlank()) {
            log("Config missing")
            return
        }

        val now = System.currentTimeMillis()
        if (now - prefs.lastHeartbeatAt > 30_000L) {
            val hb = api.heartbeat()
            prefs.lastHeartbeatAt = now
            log("Heartbeat: $hb")
        }

        if (busy) return

        val claim = api.claimNext() ?: return
        busy = true

        prefs.activeRequestId = claim.requestId
        prefs.activeAssignedSlot = claim.assignedSlot
        prefs.activeOperator = claim.operator
        prefs.activeTopupNumber = claim.topupNumber
        prefs.activeAmount = claim.amount.toString()

        val finalDialCode = buildFinalDialCode(
            template = claim.dialTemplate,
            number = claim.topupNumber,
            amount = claim.amount,
            pin = claim.retailerSecretPin
        )

        log("Claimed ${claim.requestId} / ${claim.operator} / ${claim.assignedSlot}")
        updateNotification("Dialing ${claim.operator} ${claim.topupNumber}")

        mainHandler.post {
            dialUssd(finalDialCode)
            mainHandler.removeCallbacks(timeoutRunnable)
            mainHandler.postDelayed(timeoutRunnable, 30_000L)
        }
    }

    private fun buildFinalDialCode(
        template: String,
        number: String,
        amount: Double,
        pin: String
    ): String {
        return template
            .replace("{NUMBER}", number)
            .replace("{AMOUNT}", formatAmount(amount))
            .replace("{PIN}", pin)
    }

    private fun formatAmount(value: Double): String {
        return if (value % 1.0 == 0.0) {
            value.toInt().toString()
        } else {
            DecimalFormat("0.##").format(value)
        }
    }

    @SuppressLint("MissingPermission")
    private fun dialUssd(code: String) {
        val safe = code.replace("#", Uri.encode("#"))
        val intent = Intent(Intent.ACTION_CALL, Uri.parse("tel:$safe")).apply {
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        }
        startActivity(intent)
    }

    private fun registerResultReceiver() {
        val filter = IntentFilter(WorkerConstants.ACTION_USSD_RESULT)
        if (Build.VERSION.SDK_INT >= 33) {
            registerReceiver(resultReceiver, filter, RECEIVER_NOT_EXPORTED)
        } else {
            @Suppress("DEPRECATION")
            registerReceiver(resultReceiver, filter)
        }
    }

    private fun startAsForeground() {
        createNotificationChannel()

        val notification = NotificationCompat.Builder(this, WorkerConstants.NOTIFICATION_CHANNEL_ID)
            .setContentTitle("Z Worker")
            .setContentText("Idle")
            .setSmallIcon(android.R.drawable.stat_notify_sync)
            .setOngoing(true)
            .build()

        startForeground(WorkerConstants.NOTIFICATION_ID, notification)
    }

    private fun updateNotification(text: String) {
        createNotificationChannel()
        val notification = NotificationCompat.Builder(this, WorkerConstants.NOTIFICATION_CHANNEL_ID)
            .setContentTitle("Z Worker")
            .setContentText(text)
            .setSmallIcon(android.R.drawable.stat_notify_sync)
            .setOngoing(true)
            .build()

        val nm = getSystemService(NotificationManager::class.java)
        nm.notify(WorkerConstants.NOTIFICATION_ID, notification)
    }

    private fun createNotificationChannel() {
        if (Build.VERSION.SDK_INT >= 26) {
            val nm = getSystemService(NotificationManager::class.java)
            val channel = NotificationChannel(
                WorkerConstants.NOTIFICATION_CHANNEL_ID,
                "Worker",
                NotificationManager.IMPORTANCE_LOW
            )
            nm.createNotificationChannel(channel)
        }
    }

    private fun log(message: String) {
        prefs.lastLog = message
    }
}