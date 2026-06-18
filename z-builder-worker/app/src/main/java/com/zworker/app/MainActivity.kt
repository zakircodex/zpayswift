package com.zworker.app

import android.Manifest
import android.content.Intent
import android.graphics.Color
import android.os.Build
import android.os.Bundle
import android.provider.Settings
import android.view.View
import android.view.ViewGroup
import android.widget.ArrayAdapter
import android.widget.Button
import android.widget.Spinner
import android.widget.TextView
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat

class MainActivity : AppCompatActivity() {

    private lateinit var prefs: Prefs

    private lateinit var spSim1Operator: Spinner
    private lateinit var spSim2Operator: Spinner
    private lateinit var tvAppTitle: TextView
    private lateinit var tvConnectionInfo: TextView
    private lateinit var tvStatusCard: TextView
    private lateinit var tvDeviceId: TextView
    private lateinit var tvAccessibility: TextView
    private lateinit var tvLog: TextView

    private val operatorItems = listOf("", "GP", "ROBI", "BL", "AIRTEL", "TT")

    private val permissionLauncher =
        registerForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { refreshUi() }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)

        prefs = Prefs(this)

        tvAppTitle = findViewById(R.id.tvAppTitle)
        tvConnectionInfo = findViewById(R.id.tvConnectionInfo)
        tvStatusCard = findViewById(R.id.tvStatusCard)
        tvDeviceId = findViewById(R.id.tvDeviceId)
        spSim1Operator = findViewById(R.id.spSim1Operator)
        spSim2Operator = findViewById(R.id.spSim2Operator)
        tvAccessibility = findViewById(R.id.tvAccessibility)
        tvLog = findViewById(R.id.tvLog)

        findViewById<View>(R.id.rootMain)?.requestFocus()

        setupSpinners()
        clearAllFocusAndHideKeyboard()

        findViewById<Button>(R.id.btnSave).setOnClickListener {
            saveConfig()
            refreshUi()
            clearAllFocusAndHideKeyboard()
        }

        findViewById<Button>(R.id.btnTestConnection).setOnClickListener {
            saveConfig()
            testConnection()
            clearAllFocusAndHideKeyboard()
        }

        findViewById<Button>(R.id.btnOpenAccessibility).setOnClickListener {
            clearAllFocusAndHideKeyboard()
            startActivity(Intent(Settings.ACTION_ACCESSIBILITY_SETTINGS))
        }

        findViewById<Button>(R.id.btnStart).setOnClickListener {
            saveConfig()
            requestNeededPermissions()
            val intent = Intent(this, WorkerForegroundService::class.java).apply {
                action = WorkerConstants.ACTION_START_WORKER
            }
            ContextCompat.startForegroundService(this, intent)
            prefs.lastLog = "Worker started"
            refreshUi()
        }

        findViewById<Button>(R.id.btnStop).setOnClickListener {
            val intent = Intent(this, WorkerForegroundService::class.java).apply {
                action = WorkerConstants.ACTION_STOP_WORKER
            }
            startService(intent)
            prefs.lastLog = "Worker stopped"
            refreshUi()
        }

        loadConfig()
        refreshUi()
    }

    override fun onResume() {
        super.onResume()
        clearAllFocusAndHideKeyboard()
        refreshUi()
    }

    private fun setupSpinners() {
        val adapter = object : ArrayAdapter<String>(
            this,
            android.R.layout.simple_spinner_item,
            operatorItems
        ) {
            override fun getView(position: Int, convertView: View?, parent: ViewGroup): View {
                val view = super.getView(position, convertView, parent)
                (view as? TextView)?.apply {
                    text = displayOperator(getItem(position).orEmpty())
                    setTextColor(Color.BLACK)
                    textSize = 18f
                    setPadding(18, 12, 18, 12)
                }
                return view
            }

            override fun getDropDownView(position: Int, convertView: View?, parent: ViewGroup): View {
                val view = super.getDropDownView(position, convertView, parent)
                (view as? TextView)?.apply {
                    text = displayOperator(getItem(position).orEmpty())
                    setTextColor(Color.BLACK)
                    textSize = 18f
                    setPadding(18, 16, 18, 16)
                }
                return view
            }
        }
        adapter.setDropDownViewResource(android.R.layout.simple_spinner_dropdown_item)

        spSim1Operator.adapter = adapter
        spSim2Operator.adapter = adapter
    }

    private fun displayOperator(value: String): String {
        return when (value.uppercase()) {
            "" -> "Select operator"
            "GP" -> "GP"
            "ROBI" -> "Robi"
            "BL" -> "Banglalink"
            "AIRTEL" -> "Airtel"
            "TT" -> "Teletalk"
            else -> value
        }
    }

    private fun loadConfig() {
        tvAppTitle.text = BuildConfig.ZB_APP_NAME.ifBlank { getString(R.string.app_name) }
        tvConnectionInfo.text = "API: ${prefs.baseUrl}\nApp ID: ${prefs.workerAppId.ifBlank { "embedded" }}"
        tvDeviceId.text = "Device ID: ${prefs.deviceId}"

        spSim1Operator.setSelection(operatorItems.indexOf(prefs.sim1Operator).coerceAtLeast(0))
        spSim2Operator.setSelection(operatorItems.indexOf(prefs.sim2Operator).coerceAtLeast(0))

        tvLog.text = prefs.lastLog
    }

    private fun saveConfig() {
        prefs.sim1Operator = spSim1Operator.selectedItem?.toString().orEmpty()
        prefs.sim2Operator = spSim2Operator.selectedItem?.toString().orEmpty()
        prefs.lastLog = "SIM setup saved: ${simSummary()}"
        tvDeviceId.text = "Device ID: ${prefs.deviceId}"
        tvStatusCard.text = "Status: SIM setup saved\n${simSummary()}"
        tvLog.text = prefs.lastLog
    }

    private fun testConnection() {
        tvStatusCard.text = "Status: Testing server connection...\n${simSummary()}"
        tvLog.text = "Sending heartbeat..."
        Thread {
            val ok = try {
                ApiClient(prefs).heartbeat()
            } catch (_: Exception) {
                false
            }
            runOnUiThread {
                if (ok) {
                    prefs.lastLog = "Server connected. Heartbeat saved. ${simSummary()}"
                    tvStatusCard.text = "Status: CONNECTED ✅\n${simSummary()}"
                } else {
                    prefs.lastLog = "Connection failed. Check internet, app token, or server API."
                    tvStatusCard.text = "Status: CONNECTION FAILED ❌\n${simSummary()}"
                }
                refreshUi()
            }
        }.start()
    }

    private fun simSummary(): String {
        return "SIM1: ${displayOperator(prefs.sim1Operator).replace("Select operator", "Not set")} | SIM2: ${displayOperator(prefs.sim2Operator).replace("Select operator", "Not set")}"
    }

    private fun refreshUi() {
        val enabled = isAccessibilityEnabled()
        tvAccessibility.text = if (enabled) {
            "Accessibility: ENABLED"
        } else {
            "Accessibility: DISABLED"
        }
        if (tvStatusCard.text.isNullOrBlank() || tvStatusCard.text.toString().contains("Checking")) {
            tvStatusCard.text = "Status: Ready\n${simSummary()}"
        }
        tvLog.text = prefs.lastLog
    }

    private fun requestNeededPermissions() {
        val perms = mutableListOf(
            Manifest.permission.CALL_PHONE,
            Manifest.permission.RECEIVE_SMS
        )

        if (Build.VERSION.SDK_INT >= 33) {
            perms += Manifest.permission.POST_NOTIFICATIONS
        }

        permissionLauncher.launch(perms.toTypedArray())
    }

    private fun isAccessibilityEnabled(): Boolean {
        val expected = "$packageName/${UssdAccessibilityService::class.java.canonicalName}"
        val enabledServices =
            Settings.Secure.getString(contentResolver, Settings.Secure.ENABLED_ACCESSIBILITY_SERVICES)
                ?: return false
        return enabledServices.split(':').any { it.equals(expected, ignoreCase = true) }
    }

    private fun clearAllFocusAndHideKeyboard() {
        try {
            currentFocus?.clearFocus()
        } catch (_: Exception) {
        }

        try {
            findViewById<View>(R.id.rootMain)?.requestFocus()
        } catch (_: Exception) {
        }

        try {
            val imm = getSystemService(INPUT_METHOD_SERVICE) as? android.view.inputmethod.InputMethodManager
            imm?.hideSoftInputFromWindow(window.decorView.windowToken, 0)
        } catch (_: Exception) {
        }
    }
}
