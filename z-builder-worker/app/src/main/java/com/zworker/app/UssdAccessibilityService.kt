package com.zworker.app

import android.accessibilityservice.AccessibilityService
import android.content.Intent
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.os.SystemClock
import android.view.accessibility.AccessibilityEvent
import android.view.accessibility.AccessibilityNodeInfo

class UssdAccessibilityService : AccessibilityService() {

    private lateinit var prefs: Prefs
    private val mainHandler = Handler(Looper.getMainLooper())

    @Volatile
    private var lastEmitAt: Long = 0L

    @Volatile
    private var lastEmitStatus: String = ""

    @Volatile
    private var pendingFinish = false

    @Volatile
    private var simChooserHandledForRequestId: String = ""

    @Volatile
    private var lastContinueHandledAt: Long = 0L

    override fun onServiceConnected() {
        super.onServiceConnected()
        prefs = Prefs(this)
    }

    override fun onInterrupt() = Unit

    override fun onAccessibilityEvent(event: AccessibilityEvent?) {
        if (event == null) return
        if (!::prefs.isInitialized) prefs = Prefs(this)

        val requestId = prefs.activeRequestId
        if (requestId.isBlank()) return

        if (simChooserHandledForRequestId.isNotBlank() && simChooserHandledForRequestId != requestId) {
            simChooserHandledForRequestId = ""
        }

        if (pendingFinish) return

        val root = rootInActiveWindow
        val eventText = buildEventText(event)
        val rootText = collectText(root).trim()

        val combinedText = listOf(eventText, rootText)
            .filter { it.isNotBlank() }
            .joinToString("\n")
            .trim()

        // 1) SIM chooser handle
        if (root != null && shouldTrySimChooser(combinedText, requestId)) {
            if (handleSimChooser(root, combinedText)) {
                simChooserHandledForRequestId = requestId
                return
            }
        }

        if (combinedText.isBlank()) return

        val lower = combinedText.lowercase()

        // 2) Continue prompt এলে auto "1" send করবে
        if (root != null && isContinuePrompt(lower)) {
            val now = SystemClock.elapsedRealtime()
            if (now - lastContinueHandledAt > 2500L) {
                if (fillUssdReplyAndSend(root, "1")) {
                    lastContinueHandledAt = now
                    return
                }
            }
        }

        // 3) FAIL আগে
        if (isRealFailure(lower)) {
            pendingFinish = true
            scheduleFinish(
                status = "FAILED",
                message = guessFailureMessage(lower),
                raw = combinedText.take(2500)
            )
            return
        }

        // 4) SUCCESS
        if (isRealSuccess(lower)) {
            pendingFinish = true
            scheduleFinish(
                status = "SUCCESS",
                message = "Recharge successful",
                raw = combinedText.take(2500)
            )
            return
        }
    }

    private fun shouldTrySimChooser(text: String, requestId: String): Boolean {
        if (requestId == simChooserHandledForRequestId) return false
        val lower = text.lowercase()

        return lower.contains("choose sim for this call") ||
                lower.contains("choose sim") ||
                lower.contains("select sim") ||
                lower.contains("choose a sim") ||
                lower.contains("always use") ||
                lower.contains("make calls with") ||
                lower.contains("call with") ||
                lower.contains("calls with") ||
                lower.contains("complete action using") ||
                lower.contains("phone account") ||
                lower.contains("select subscription") ||
                lower.contains("choose subscription") ||
                lower.contains("default sim") ||
                lower.contains("sim 1") ||
                lower.contains("sim1") ||
                lower.contains("sim 2") ||
                lower.contains("sim2") ||
                lower.contains("slot 1") ||
                lower.contains("slot1") ||
                lower.contains("slot 2") ||
                lower.contains("slot2") ||
                lower.contains("card 1") ||
                lower.contains("card1") ||
                lower.contains("card 2") ||
                lower.contains("card2") ||
                (lower.contains("u mobile") && lower.contains("grameenphone"))
    }

    private fun handleSimChooser(root: AccessibilityNodeInfo, text: String): Boolean {
        val slot = prefs.activeAssignedSlot.trim().uppercase()
        if (slot.isBlank()) return false

        val lower = text.lowercase()
        val looksLikeChooser = lower.contains("choose sim for this call") ||
                lower.contains("choose sim") ||
                lower.contains("make calls with") ||
                lower.contains("make calls") ||
                lower.contains("select sim") ||
                (lower.contains("robi") && lower.contains("grameenphone"))

        if (!looksLikeChooser) return false

        val targets = when (slot) {
            "SIM1" -> buildList {
                add("1")
                add("SIM 1")
                add("SIM1")
                add("SLOT 1")
                add("CARD 1")
                addAll(operatorAliases(prefs.sim1Operator))
            }

            "SIM2" -> buildList {
                add("2")
                add("SIM 2")
                add("SIM2")
                add("SLOT 2")
                add("CARD 2")
                addAll(operatorAliases(prefs.sim2Operator))
            }

            else -> emptyList()
        }

        return clickExactText(root, targets)
    }

    private fun isContinuePrompt(text: String): Boolean {
        return text.contains("do you want to continue") ||
                (text.contains("1 - yes") && text.contains("2 - no")) ||
                (text.contains("1. yes") && text.contains("2. no")) ||
                (text.contains("1 yes") && text.contains("2 no")) ||
                (text.contains("1- yes") && text.contains("2- no")) ||
                (text.contains("1-yes") && text.contains("2-no"))
    }

    private fun fillUssdReplyAndSend(root: AccessibilityNodeInfo, value: String): Boolean {
        val inputNode = findEditableNode(root) ?: return false

        val args = Bundle().apply {
            putCharSequence(
                AccessibilityNodeInfo.ACTION_ARGUMENT_SET_TEXT_CHARSEQUENCE,
                value
            )
        }

        val setOk = inputNode.performAction(AccessibilityNodeInfo.ACTION_SET_TEXT, args)
        if (!setOk) return false

        mainHandler.postDelayed({
            val latestRoot = rootInActiveWindow ?: return@postDelayed
            clickExactText(latestRoot, listOf("Send", "SEND"))

            try{
                inputNode.performAction(AccessibilityNodeInfo.ACTION_CLEAR_FOCUS)
            } catch (_: Exception) {

            }

        }, 250L)

        return true
    }

    private fun scheduleFinish(status: String, message: String, raw: String) {
        mainHandler.postDelayed({
            // আগে backend-এ result পাঠাও
            emitResultOnce(status, message, raw)

            // তারপর popup dismiss করার চেষ্টা করবে
            mainHandler.postDelayed({
                tryDismissUssdOnly()
                pendingFinish = false
            }, 700L)

        }, 1800L)
    }

    private fun tryDismissUssdOnly() {
        val root = rootInActiveWindow ?: return
        val currentPkg = root.packageName?.toString().orEmpty()

        // নিজের app হলে কিছুই করবে না
        if (currentPkg == packageName) return

        // শুধু explicit dismiss buttons click করবে
        clickExactText(root, listOf(
            "Cancel", "CANCEL", "বাতিল", "বন্ধ",
            "OK", "Ok", "Close", "Dismiss", "End", "Done"
        ))

        // IMPORTANT:
        // এখানে কোনো GLOBAL_ACTION_BACK নাই
    }

    private fun buildEventText(event: AccessibilityEvent): String {
        val sb = StringBuilder()

        event.text?.forEach { item ->
            val s = item?.toString()?.trim().orEmpty()
            if (s.isNotBlank()) {
                sb.append(s).append('\n')
            }
        }

        event.contentDescription?.toString()?.trim()?.takeIf { it.isNotBlank() }?.let {
            sb.append(it).append('\n')
        }

        return sb.toString().trim()
    }

    private fun emitResultOnce(status: String, message: String, raw: String) {
        val now = SystemClock.elapsedRealtime()
        if (now - lastEmitAt < 4000L && lastEmitStatus == status) return

        lastEmitAt = now
        lastEmitStatus = status

        val i = Intent(WorkerConstants.ACTION_USSD_RESULT).apply {
            putExtra(WorkerConstants.EXTRA_RESULT_STATUS, status)
            putExtra(WorkerConstants.EXTRA_RESULT_MESSAGE, message)
            putExtra(WorkerConstants.EXTRA_RESULT_RAW, raw)
        }
        sendBroadcast(i)
    }

    private fun clickExactText(root: AccessibilityNodeInfo, targets: List<String>): Boolean {
        val normalizedTargets = targets.map { normalizeText(it) }.toSet()

        fun dfs(node: AccessibilityNodeInfo?): Boolean {
            if (node == null) return false

            val texts = listOfNotNull(
                node.text?.toString(),
                node.contentDescription?.toString()
            )

            for (t in texts) {
                val nt = normalizeText(t)
                if (nt in normalizedTargets) {
                    val clickable = findClickable(node)
                    if (clickable?.performAction(AccessibilityNodeInfo.ACTION_CLICK) == true) {
                        return true
                    }
                }
            }

            for (i in 0 until node.childCount) {
                if (dfs(node.getChild(i))) return true
            }
            return false
        }

        return dfs(root)
    }

    private fun normalizeText(s: String): String {
        return s.trim()
            .replace('\n', ' ')
            .replace(Regex("\\s+"), " ")
            .uppercase()
    }

    private fun findClickable(node: AccessibilityNodeInfo?): AccessibilityNodeInfo? {
        var current = node
        while (current != null) {
            if (current.isClickable) return current
            current = current.parent
        }
        return null
    }

    private fun findEditableNode(node: AccessibilityNodeInfo?): AccessibilityNodeInfo? {
        if (node == null) return null

        if (node.isEditable) return node

        val cls = node.className?.toString().orEmpty()
        if (cls.contains("EditText", ignoreCase = true)) {
            return node
        }

        for (i in 0 until node.childCount) {
            val found = findEditableNode(node.getChild(i))
            if (found != null) return found
        }

        return null
    }

    private fun collectText(node: AccessibilityNodeInfo?): String {
        if (node == null) return ""
        val sb = StringBuilder()

        node.text?.toString()?.trim()?.takeIf { it.isNotBlank() }?.let {
            sb.append(it).append('\n')
        }
        node.contentDescription?.toString()?.trim()?.takeIf { it.isNotBlank() }?.let {
            sb.append(it).append('\n')
        }

        for (i in 0 until node.childCount) {
            sb.append(collectText(node.getChild(i)))
        }

        return sb.toString()
    }

    private fun isRealSuccess(text: String): Boolean {
        val hasSuccessful = text.contains("is successful") ||
                text.contains("successful") ||
                text.contains("successfully")

        val hasTrx = text.contains("trxid") ||
                text.contains("trx id") ||
                text.contains("transaction id")

        val hasBalance = text.contains("balance:")
        val hasFee = text.contains("fee") || text.contains("vat")
        val hasRechargeText = text.contains("mobile recharge request") ||
                text.contains("recharge request")

        return (hasSuccessful && hasTrx) ||
                (hasSuccessful && hasBalance) ||
                (hasRechargeText && hasTrx) ||
                (hasSuccessful && hasFee)
    }

    private fun isRealFailure(text: String): Boolean {
        val failurePhrases = listOf(
            "failed",
            "failure",
            "unsuccessful",
            "not successful",
            "invalid",
            "incorrect",
            "wrong number",
            "not enough",
            "insufficient",
            "denied",
            "not allowed",
            "request failed",
            "recharge failed",
            "topup failed",
            "amount is below minimum topup amount",
            "below minimum topup amount",
            "minimum topup amount"
        )
        return failurePhrases.any { text.contains(it) }
    }

    private fun guessFailureMessage(text: String): String {
        return when {
            text.contains("insufficient") || text.contains("not enough") -> "Insufficient balance"
            text.contains("below minimum topup amount") || text.contains("Amount is below minimum topUp amount") -> "Amount below"
            text.contains("wrong number") || text.contains("invalid") -> "Invalid number or request"
            text.contains("incorrect") -> "Incorrect PIN or input"
            text.contains("denied") -> "Request denied"
            else -> "Recharge failed"
        }
    }

    private fun operatorAliases(op: String): List<String> {
        return when (op.trim().uppercase()) {
            "GP" -> listOf("GP", "GRAMEENPHONE", "GRAMEEN PHONE", "Grameenphone")
            "ROBI" -> listOf("ROBI", "U MOBILE", "UMOBILE", "ROBIAXIATA", "Robi")
            "BL" -> listOf("BL", "BANGLALINK", "BANGLA LINK", "Banglalink")
            "AIRTEL" -> listOf("AIRTEL", "Airtel")
            "TT" -> listOf("TT", "TELETALK", "TELE TALK", "Teletalk")
            else -> emptyList()
        }
    }
}