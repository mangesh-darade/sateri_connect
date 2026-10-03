<?php
/**
 * AI Copilot Slide Drawer.
 * Context-aware AI Assistant across Sateri Connect.
 */
?>
<div class="ai-copilot-backdrop" id="aiCopilotBackdrop"></div>
<aside class="ai-copilot-drawer shadow-lg" id="aiCopilotDrawer" aria-label="AI Copilot">
    <div class="ai-copilot-header">
        <div class="d-flex align-items-center gap-2">
            <span class="ai-copilot-badge-icon">
                <i class="fas fa-wand-magic-sparkles text-success"></i>
            </span>
            <div>
                <h6 class="mb-0 fw-bold d-flex align-items-center gap-2">
                    AI Copilot
                    <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-0.5 rounded-pill" id="copilotScreenBadge" style="font-size: 0.7rem;">Detecting...</span>
                </h6>
                <small class="text-muted" style="font-size: 0.72rem;">Screen-aware WhatsApp Automation Assistant</small>
            </div>
        </div>
        <button type="button" class="btn btn-sm btn-link text-secondary p-1" id="btnCloseAiCopilot" title="Close Copilot">
            <i class="fas fa-times fs-6"></i>
        </button>
    </div>

    <div class="ai-copilot-context-bar d-flex align-items-center justify-content-between px-3 py-1 bg-light border-bottom">
        <span class="small text-muted" id="copilotContextHint" style="font-size: 0.75rem;">
            <i class="fas fa-location-dot text-primary me-1"></i> Active: <strong id="copilotActivePageName">Current Page</strong>
        </span>
        <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-link btn-sm text-muted p-0" id="btnCopilotHistory" title="View recent prompt history" style="font-size: 0.75rem;">
                <i class="fas fa-clock-rotate-left me-1"></i> History
            </button>
            <span class="text-muted" style="font-size: 0.7rem;">·</span>
            <button type="button" class="btn btn-link btn-sm text-muted p-0" id="btnCopilotClearChat" title="Clear chat" style="font-size: 0.75rem;">
                <i class="fas fa-rotate-left me-1"></i> Reset
            </button>
        </div>
    </div>

    <div class="ai-copilot-history-panel d-none p-2 bg-light border-bottom" id="copilotHistoryPanel" style="max-height: 240px; overflow-y: auto;">
        <div class="d-flex justify-content-between align-items-center mb-2 px-1">
            <span class="fw-bold small text-secondary" style="font-size: 0.75rem;"><i class="fas fa-history me-1 text-success"></i> Prompt History</span>
            <button type="button" class="btn-close" id="btnCloseHistoryPanel" aria-label="Close" style="font-size: 0.65rem;"></button>
        </div>
        <div id="copilotHistoryList" class="small">
            <div class="text-muted text-center py-2" style="font-size: 0.75rem;">Loading history...</div>
        </div>
    </div>

    <div class="ai-copilot-chips-wrap px-3 py-2 border-bottom" id="copilotChipsWrap">
        <div class="small fw-semibold text-secondary mb-1" style="font-size: 0.72rem;">Suggested prompts for this screen:</div>
        <div class="d-flex flex-wrap gap-1" id="copilotPromptChips">
            <!-- Injected dynamically via JS based on current page -->
        </div>
    </div>

    <div class="ai-copilot-body" id="copilotMessages">
        <div class="copilot-msg copilot-msg-ai">
            <div class="copilot-avatar"><i class="fas fa-robot"></i></div>
            <div class="copilot-bubble">
                <p class="mb-1"><strong>Hello! I am Sateri AI Copilot.</strong> 👋</p>
                <p class="mb-0 small" id="copilotWelcomeText">
                    I adapt to whichever screen you are on. How can I help you today?
                </p>
            </div>
        </div>
    </div>

    <div class="ai-copilot-footer p-2 border-top bg-white">
        <form id="copilotForm" class="d-flex gap-2 align-items-end" onsubmit="return false;">
            <div class="flex-grow-1 position-relative">
                <textarea id="copilotInput" class="form-control" rows="1" placeholder="Ask anything or describe what to automate..." style="resize: none; font-size: 0.85rem; max-height: 100px;"></textarea>
            </div>
            <button type="button" class="btn btn-wa px-3" id="copilotSendBtn" title="Send instruction">
                <i class="fas fa-paper-plane"></i>
            </button>
        </form>
        <div class="d-flex justify-content-between align-items-center mt-1 px-1">
            <span class="text-muted" style="font-size: 0.68rem;"><i class="fab fa-google text-primary me-1"></i>Gemini Flash · Context-aware</span>
            <span class="text-muted" style="font-size: 0.68rem;">Press Enter to send</span>
        </div>
    </div>
</aside>
