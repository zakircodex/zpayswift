package com.zworker.app

object WorkerConstants {
    const val ACTION_START_WORKER = "com.zworker.app.action.START_WORKER"
    const val ACTION_STOP_WORKER = "com.zworker.app.action.STOP_WORKER"
    const val ACTION_USSD_RESULT = "com.zworker.app.action.USSD_RESULT"

    const val EXTRA_RESULT_STATUS = "result_status"
    const val EXTRA_RESULT_MESSAGE = "result_message"
    const val EXTRA_RESULT_RAW = "result_raw"

    const val NOTIFICATION_CHANNEL_ID = "worker_channel"
    const val NOTIFICATION_ID = 2001
}