package com.zworker.app

import org.json.JSONObject
import java.io.BufferedReader
import java.io.OutputStreamWriter
import java.net.HttpURLConnection
import java.net.URL

data class ClaimResponse(
    val requestId: String,
    val topupNumber: String,
    val operator: String,
    val amount: Double,
    val assignedSlot: String,
    val dialTemplate: String,
    val retailerSecretPin: String
)

class ApiClient(private val prefs: Prefs) {

    fun heartbeat(): Boolean {
        val cfg = prefs.getConfig()
        if (cfg.baseUrl.isBlank() || cfg.workerKey.isBlank() || cfg.deviceId.isBlank()) return false

        val body = JSONObject().apply {
            put("app_id", prefs.workerAppId)
            put("device_id", cfg.deviceId)
            put("device_name", android.os.Build.MODEL ?: "Android Worker")
            put("worker_enabled", true)
            put("accessibility_enabled", true)
            put("app_version", BuildConfig.VERSION_NAME)
            put("sim_slots", JSONObject().apply {
                put("SIM1", JSONObject().apply {
                    put("operator", cfg.sim1Operator)
                    put("active", cfg.sim1Operator.isNotBlank())
                })
                put("SIM2", JSONObject().apply {
                    put("operator", cfg.sim2Operator)
                    put("active", cfg.sim2Operator.isNotBlank())
                })
            })
        }

        val res = postJson(
            "${cfg.baseUrl}/api/my_site/worker_app_heartbeat.php",
            body,
            workerHeaders()
        )

        return res?.optBoolean("ok", false) == true
    }

    fun claimNext(): ClaimResponse? {
        val cfg = prefs.getConfig()
        if (cfg.baseUrl.isBlank() || cfg.workerKey.isBlank() || cfg.deviceId.isBlank()) return null

        val body = JSONObject().apply {
            put("app_id", prefs.workerAppId)
            put("device_id", cfg.deviceId)
        }

        val res = postJson(
            "${cfg.baseUrl}/api/my_site/worker_app_claim.php",
            body,
            workerHeaders()
        ) ?: return null

        if (!res.optBoolean("ok", false)) return null

        val data = res.optJSONObject("data") ?: return null
        return ClaimResponse(
            requestId = data.optString("request_id"),
            topupNumber = data.optString("topup_number"),
            operator = data.optString("operator"),
            amount = data.optDouble("amount", 0.0),
            assignedSlot = data.optString("assigned_slot"),
            dialTemplate = data.optString("dial_template"),
            retailerSecretPin = data.optString("retailer_secret_pin")
        )
    }

    fun sendResult(
        requestId: String,
        resultStatus: String,
        resultMessage: String,
        rawResponse: String
    ): Boolean {
        val cfg = prefs.getConfig()
        if (cfg.baseUrl.isBlank() || cfg.workerKey.isBlank() || cfg.deviceId.isBlank()) return false

        val body = JSONObject().apply {
            put("app_id", prefs.workerAppId)
            put("device_id", cfg.deviceId)
            put("request_id", requestId)
            put("result_status", resultStatus)
            put("result_message", resultMessage)
            put("raw_response", rawResponse)
        }

        val res = postJson(
            "${cfg.baseUrl}/api/my_site/worker_app_result.php",
            body,
            workerHeaders()
        )

        return res?.optBoolean("ok", false) == true
    }

    private fun workerHeaders(): Map<String, String> {
        return mapOf(
            "X-ZBUILDER-WORKER-APP-ID" to prefs.workerAppId,
            "X-ZBUILDER-WORKER-TOKEN" to prefs.workerKey
        )
    }

    private fun postJson(
        url: String,
        body: JSONObject,
        extraHeaders: Map<String, String> = emptyMap()
    ): JSONObject? {
        val conn = (URL(url).openConnection() as HttpURLConnection).apply {
            requestMethod = "POST"
            connectTimeout = 15000
            readTimeout = 30000
            doInput = true
            doOutput = true
            setRequestProperty("Content-Type", "application/json")
            extraHeaders.forEach { (k, v) -> setRequestProperty(k, v) }
        }

        return try {
            OutputStreamWriter(conn.outputStream, Charsets.UTF_8).use { writer ->
                writer.write(body.toString())
                writer.flush()
            }

            val stream = if (conn.responseCode in 200..299) conn.inputStream else conn.errorStream
            val text = BufferedReader(stream.reader()).use { it.readText() }
            JSONObject(text)
        } catch (_: Exception) {
            null
        } finally {
            conn.disconnect()
        }
    }
}
