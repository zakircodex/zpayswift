package com.zworker.app

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.os.SystemClock
import android.provider.Telephony
import java.util.Locale

class SmsReceiver : BroadcastReceiver() {

    companion object {
        @Volatile
        private var lastSmsHash: Int = 0

        @Volatile
        private var lastSmsAt: Long = 0L
    }

    override fun onReceive(context: Context, intent: Intent?) {
        if (intent?.action != Telephony.Sms.Intents.SMS_RECEIVED_ACTION) return

        val prefs = Prefs(context)
        val requestId = prefs.activeRequestId.trim()
        if (requestId.isBlank()) return

        val messages = Telephony.Sms.Intents.getMessagesFromIntent(intent)
        if (messages.isNullOrEmpty()) return

        val fullBody = buildString {
            for (sms in messages) {
                append(sms.messageBody.orEmpty())
            }
        }.normalizeSmsText()

        if (fullBody.isBlank()) return

        // duplicate guard
        val now = SystemClock.elapsedRealtime()
        val smsHash = fullBody.hashCode()
        if (smsHash == lastSmsHash && now - lastSmsAt < 5000L) return
        lastSmsHash = smsHash
        lastSmsAt = now

        val parsed = parseTopupSms(fullBody) ?: return

        prefs.lastLog = "SMS matched: ${fullBody.take(300)}"

        val out = Intent(WorkerConstants.ACTION_USSD_RESULT).apply {
            setPackage(context.packageName)
            putExtra(WorkerConstants.EXTRA_RESULT_STATUS, parsed.status)
            putExtra(WorkerConstants.EXTRA_RESULT_MESSAGE, parsed.message)
            putExtra(WorkerConstants.EXTRA_RESULT_RAW, fullBody)
        }

        context.sendBroadcast(out)
    }

    private fun parseTopupSms(body: String): SmsParseResult? {
        val x = body.lowercase(Locale.getDefault())

        val txId = extractTransactionId(body)
        val refNo = extractRefNo(body)

        // SUCCESS:
        // successful word must exist + transaction id style value must exist
        val hasSuccessWord =
            x.contains("successful") ||
                    x.contains("is successful") ||
                    x.contains("successfully")

        val hasAnyTxnId = txId.isNotBlank()

        if (hasSuccessWord && hasAnyTxnId) {
            return SmsParseResult(
                status = "SUCCESS",
                message = "Recharge successful ($txId)"
            )
        }

        // DUPLICATE / FAILED
        val isDuplicate =
            x.contains("duplicate transaction") ||
                    x.contains("duplicate transaction detected")

        if (isDuplicate) {
            val code = if (refNo.isNotBlank()) refNo else txId
            return SmsParseResult(
                status = "FAILED",
                message = if (code.isNotBlank()) {
                    "Duplicate transaction detected ($code)"
                } else {
                    "Duplicate transaction detected"
                }
            )
        }

        val hasFailWord =
            x.contains("failed") ||
                    x.contains("failed due to") ||
                    x.contains("unsuccessful") ||
                    x.contains("not successful")

        if (hasFailWord) {
            val code = if (refNo.isNotBlank()) refNo else txId
            return SmsParseResult(
                status = "FAILED",
                message = if (code.isNotBlank()) {
                    "Recharge failed ($code)"
                } else {
                    "Recharge failed"
                }
            )
        }

        return null
    }

    private fun extractTransactionId(text: String): String {
        val patterns = listOf(
            // Transaction ID BD160426102700082620
            Regex("""(?i)\btransaction\s*id\s*[: ]\s*([A-Z0-9]+)\b"""),
            // TrxID: 7559WXS9
            Regex("""(?i)\btrx\s*id\s*[: ]\s*([A-Z0-9]+)\b"""),
            // TRXID 7559WXS9
            Regex("""(?i)\btrxid\s*[: ]\s*([A-Z0-9]+)\b"""),
            // fallback: standalone BD1604.... style token
            Regex("""\b(BD[A-Z0-9]{8,})\b""", RegexOption.IGNORE_CASE)
        )

        for (r in patterns) {
            val m = r.find(text)
            if (m != null) {
                val v = m.groupValues.getOrNull(1).orEmpty().trim()
                if (v.isNotBlank()) return v
            }
        }

        return ""
    }

    private fun extractRefNo(text: String): String {
        val patterns = listOf(
            Regex("""(?i)\bref\s*no\s*:?\s*([A-Z0-9]+)\b"""),
            Regex("""(?i)\breference\s*no\s*:?\s*([A-Z0-9]+)\b""")
        )

        for (r in patterns) {
            val m = r.find(text)
            if (m != null) {
                val v = m.groupValues.getOrNull(1).orEmpty().trim()
                if (v.isNotBlank()) return v
            }
        }

        return ""
    }

    private fun String.normalizeSmsText(): String {
        return this
            .replace('\n', ' ')
            .replace('\r', ' ')
            .replace(Regex("\\s+"), " ")
            .trim()
    }

    data class SmsParseResult(
        val status: String,
        val message: String
    )
}