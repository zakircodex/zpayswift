package com.zworker.app

import android.content.Context
import android.content.SharedPreferences

data class WorkerConfig(
    val baseUrl: String,
    val workerKey: String,
    val deviceId: String,
    val sim1Operator: String,
    val sim2Operator: String
)

class Prefs(context: Context) {
    private val sp: SharedPreferences =
        context.getSharedPreferences("z_worker_prefs", Context.MODE_PRIVATE)

    var baseUrl: String
        get() = sp.getString("base_url", "") ?: ""
        set(v) = sp.edit().putString("base_url", v.trim().trimEnd('/')).apply()

    var workerKey: String
        get() = sp.getString("worker_key", "") ?: ""
        set(v) = sp.edit().putString("worker_key", v.trim()).apply()

    var deviceId: String
        get() = sp.getString("device_id", "") ?: ""
        set(v) = sp.edit().putString("device_id", v.trim()).apply()

    var sim1Operator: String
        get() = sp.getString("sim1_operator", "") ?: ""
        set(v) = sp.edit().putString("sim1_operator", normalizeOperator(v)).apply()

    var sim2Operator: String
        get() = sp.getString("sim2_operator", "") ?: ""
        set(v) = sp.edit().putString("sim2_operator", normalizeOperator(v)).apply()

    var activeRequestId: String
        get() = sp.getString("active_request_id", "") ?: ""
        set(v) = sp.edit().putString("active_request_id", v).apply()

    var activeAssignedSlot: String
        get() = sp.getString("active_assigned_slot", "") ?: ""
        set(v) = sp.edit().putString("active_assigned_slot", v).apply()

    var activeOperator: String
        get() = sp.getString("active_operator", "") ?: ""
        set(v) = sp.edit().putString("active_operator", normalizeOperator(v)).apply()

    var activeTopupNumber: String
        get() = sp.getString("active_topup_number", "") ?: ""
        set(v) = sp.edit().putString("active_topup_number", v).apply()

    var activeAmount: String
        get() = sp.getString("active_amount", "") ?: ""
        set(v) = sp.edit().putString("active_amount", v).apply()

    var lastLog: String
        get() = sp.getString("last_log", "Ready") ?: "Ready"
        set(v) = sp.edit().putString("last_log", v).apply()

    var lastHeartbeatAt: Long
        get() = sp.getLong("last_heartbeat_at", 0L)
        set(v) = sp.edit().putLong("last_heartbeat_at", v).apply()

    fun getConfig(): WorkerConfig {
        return WorkerConfig(
            baseUrl = baseUrl,
            workerKey = workerKey,
            deviceId = deviceId,
            sim1Operator = sim1Operator,
            sim2Operator = sim2Operator
        )
    }

    fun clearActiveRequest() {
        activeRequestId = ""
        activeAssignedSlot = ""
        activeOperator = ""
        activeTopupNumber = ""
        activeAmount = ""
    }

    fun normalizeOperator(value: String): String {
        return when (value.trim().uppercase()) {
            "GRAMEENPHONE", "GP" -> "GP"
            "ROBI" -> "ROBI"
            "BANGLALINK", "BL" -> "BL"
            "AIRTEL" -> "AIRTEL"
            "TELETALK", "TT" -> "TT"
            else -> value.trim().uppercase()
        }
    }
}