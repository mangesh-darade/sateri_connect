<?= $this->extend('layouts/main') ?>

<?= $this->section('styles') ?>
<style>
/* Legacy overrides kept as backup; primary layout in app.css (.chat-page-active) */
.chat-page{display:flex;flex-direction:column;height:100%;min-height:0}
.chat-page .chat-layout{flex:1;height:auto;min-height:0}
.chat-webhook-banner{flex-shrink:0}
</style>
<?= $this->endSection() ?>

<?= $this->section('content') ?>
<?php
$preContact = $selectedContact ?? $contact ?? null;
$preId = $preContact['id'] ?? ($contact_id ?? null);
$canAssign = function_exists('can') && can('chat.assign');
$canClose  = function_exists('can') && can('chat.close');
$canSend   = function_exists('can') && can('chat.send');
$inboxChannel = strtolower((string) ($inboxChannel ?? 'whatsapp'));
$inboxTitle = (string) ($inboxTitle ?? (setting('business_phone', 'WhatsApp Inbox') ?? 'WhatsApp Inbox'));
$inboxSubtitle = (string) ($inboxSubtitle ?? 'WABA Number');
?>
<div class="chat-page">
    <?php if (empty($webhookReady)): ?>
    <div class="alert alert-warning border-0 rounded-0 mb-0 py-2 px-3 small chat-webhook-banner">
        <strong>Not seeing inbound messages?</strong>
        Complete Settings → Webhooks (Verify Token + public URL + <?= esc(function_exists('whatsapp_provider_short') ? whatsapp_provider_short() : (($whatsappProvider ?? 'cheerio') === 'meta' ? 'Meta' : 'Cheerio')) ?>).
        <a href="<?= site_url('settings') ?>#tabWebhooks" class="alert-link ms-1">Open webhook setup</a>
    </div>
    <?php endif; ?>
<div id="chatLayout" class="chat-layout<?= $preId ? ' chat-thread-open' : '' ?>"
     data-contact-id="<?= esc((string) ($preId ?? ''), 'attr') ?>"
     data-contact-name="<?= esc($preContact['name'] ?? '', 'attr') ?>"
     data-contact-mobile="<?= esc($preContact['mobile'] ?? '', 'attr') ?>"
     data-current-user-id="<?= esc((string) (session('user_id') ?? ''), 'attr') ?>"
     data-channel="<?= esc($inboxChannel, 'attr') ?>">
    <aside class="chat-sidebar">
        <div class="chat-sidebar-header">
            <h2 class="chat-title">Live Chat</h2>
            <div class="chat-inbox-shell">
                <div class="chat-inbox-topline">
                    <div class="chat-inbox-identity">
                        <div class="chat-inbox-number"><?= esc($inboxTitle) ?></div>
                        <div class="chat-inbox-label"><?= esc($inboxSubtitle) ?></div>
                    </div>
                    <div class="chat-inbox-actions">
                        <a href="<?= site_url('contacts/create') ?>" class="chat-icon-btn" title="Add contact" aria-label="Add contact"><i class="fas fa-plus"></i></a>
                        <button type="button" class="chat-icon-btn" id="btnInboxFilters" title="Filters" aria-label="Filters"><i class="fas fa-filter"></i></button>
                        <button type="button" class="chat-icon-btn" id="btnOldChatsToggle" title="Show old chats first" aria-label="Show old chats first"><i class="fas fa-arrow-down-wide-short"></i></button>
                        <div class="dropdown">
                            <button type="button" class="chat-icon-btn" data-bs-toggle="dropdown" aria-expanded="false" title="Menu" aria-label="Menu"><i class="fas fa-ellipsis-v"></i></button>
                            <div class="dropdown-menu dropdown-menu-end chat-menu-dropdown">
                                <button type="button" class="dropdown-item" id="btnExportReport">Export Report</button>
                                <button type="button" class="dropdown-item" id="btnExportFormMessages">Export Form Messages</button>
                                <a class="dropdown-item" href="<?= site_url('guide/local') ?>">Read help article</a>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="chat-search chat-search-combo">
                    <div class="chat-search-type">Name/Phone <i class="fas fa-chevron-down ms-1"></i></div>
                    <div class="chat-search-input-wrap">
                        <i class="fas fa-search"></i>
                        <input type="search" id="chatSearch" class="form-control form-control-sm" placeholder="Search by name or phone">
                    </div>
                </div>
                <div class="chat-sidebar-meta chat-sidebar-meta-compact">
                    <div class="dropdown">
                        <button type="button" class="chat-scope-btn" id="chatScopeButton" data-bs-toggle="dropdown" aria-expanded="false">
                            <span id="chatSidebarAllCount">All Chats (0)</span>
                            <i class="fas fa-chevron-down"></i>
                        </button>
                        <div class="dropdown-menu chat-scope-dropdown">
                            <button type="button" class="dropdown-item active" data-scope="all">All Chats</button>
                            <button type="button" class="dropdown-item" data-scope="open">Open</button>
                            <button type="button" class="dropdown-item" data-scope="pending">Pending</button>
                            <button type="button" class="dropdown-item" data-scope="chatbot">Chatbot</button>
                            <button type="button" class="dropdown-item" data-scope="intervened">Intervened</button>
                            <button type="button" class="dropdown-item" data-scope="resolved">Resolved</button>
                            <button type="button" class="dropdown-item" data-scope="active">Active (24h)</button>
                            <button type="button" class="dropdown-item" data-scope="expired">Expired</button>
                            <button type="button" class="dropdown-item" data-scope="unassigned">Unassigned</button>
                            <button type="button" class="dropdown-item" data-scope="assigned">Assigned to Agents</button>
                            <button type="button" class="dropdown-item" data-scope="frt_exceeded">FRT Exceeded</button>
                            <button type="button" class="dropdown-item" data-scope="ctwa">CTWA</button>
                            <button type="button" class="dropdown-item" data-scope="unread">Unread</button>
                        </div>
                    </div>
                    <label class="chat-unread-toggle">
                        <span id="chatSidebarUnreadCount">Unread 0</span>
                        <input type="checkbox" id="chatUnreadToggle">
                        <span class="chat-unread-toggle-ui"></span>
                    </label>
                </div>
            </div>
        </div>
        <div class="chat-inbox-filters">
            <button type="button" class="btn btn-sm btn-wa chat-status-filter active" data-status="open">Open</button>
            <button type="button" class="btn btn-sm btn-outline-secondary chat-status-filter" data-status="pending">Pending</button>
            <button type="button" class="btn btn-sm btn-outline-secondary chat-status-filter" data-status="chatbot">Bot</button>
            <button type="button" class="btn btn-sm btn-outline-secondary chat-status-filter" data-status="resolved">Resolved</button>
            <button type="button" class="btn btn-sm btn-outline-secondary chat-status-filter" data-status="all">All</button>
        </div>
        <div class="chat-conv-list" id="chatConvList">
            <div class="p-3 text-muted text-center">Loading…</div>
        </div>
    </aside>
    <section class="chat-main">
        <div id="chatMainEmpty" class="chat-empty <?= $preId ? 'd-none' : '' ?>">
            <div class="empty-orb"><i class="fab fa-whatsapp"></i></div>
            <p>Pick a conversation</p>
            <span>Select someone from the left to view messages and reply in the 24-hour window.</span>
        </div>
        <div id="chatMainActive" class="chat-main-active <?= $preId ? '' : 'd-none' ?>" style="<?= $preId ? 'display:flex' : 'display:none' ?>">
            <div class="chat-main-header">
                <button type="button" class="btn-chat-back" id="btnChatBack" aria-label="Back to conversations"><i class="fas fa-arrow-left"></i></button>
                <div class="chat-avatar" id="chatHeaderAvatar">?</div>
                <div class="flex-grow-1 min-w-0">
                    <div class="fw-semibold text-truncate" id="chatHeaderName">Contact</div>
                    <div class="small text-muted text-truncate" id="chatHeaderMobile"></div>
                </div>
                <div class="d-flex align-items-center gap-1 flex-shrink-0">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnChatNotes" title="Internal notes">
                        <i class="fas fa-sticky-note"></i>
                    </button>
                    <?php if ($canAssign): ?>
                    <select id="chatAssignSelect" class="form-select form-select-sm" style="width:auto;max-width:130px" title="Assign agent">
                        <option value="">Unassigned</option>
                        <?php foreach (($agents ?? []) as $agent): ?>
                            <option value="<?= (int) $agent['id'] ?>"><?= esc($agent['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                    <?php if ($canClose): ?>
                    <select id="chatStatusSelect" class="form-select form-select-sm" style="width:auto;max-width:128px" title="Conversation status">
                        <option value="open">Open</option>
                        <option value="pending">Pending</option>
                        <option value="intervened">Intervened</option>
                        <option value="chatbot">Chatbot</option>
                        <option value="resolved">Resolved</option>
                    </select>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnChatClose" title="Resolve conversation">
                        <i class="fas fa-check"></i><span class="d-none d-md-inline ms-1">Resolve</span>
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary d-none" id="btnChatReopen" title="Reopen">
                        <i class="fas fa-folder-open"></i>
                    </button>
                    <?php endif; ?>
                    <?php if ($canSend): ?>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnTemplateReply" title="Template reply">
                        <i class="fas fa-file-alt"></i><span class="d-none d-sm-inline ms-1">Template</span>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <div id="chatWindowBanner" class="alert alert-warning py-2 px-3 mb-0 rounded-0 border-0 border-bottom d-none">
                Outside the 24-hour window — send an approved <strong>template</strong> first.
            </div>
            <div id="chatChannelLockBanner" class="alert alert-warning py-2 px-3 mb-0 rounded-0 border-0 border-bottom d-none">
                Outside the 24-hour messaging window for this channel. Wait for the customer to message again.
            </div>
            <div class="chat-notes-panel" id="chatNotesPanel">
                <div id="chatNotesList" class="pb-1"></div>
                <?php if ($canSend || (function_exists('can') && can('chat.view'))): ?>
                <div class="p-2 border-top d-flex gap-2">
                    <input type="text" id="chatNoteInput" class="form-control form-control-sm" placeholder="Add internal note…">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnAddChatNote">Add</button>
                </div>
                <?php endif; ?>
            </div>
            <div class="chat-messages" id="chatMessages"></div>
            <div class="position-relative">
                <div class="emoji-picker" id="emojiPicker">
                    <?php foreach (['😀','😁','😂','😊','😍','👍','🙏','🎉','🔥','✅','❌','👋','💯','🤝','❤️','😎'] as $e): ?>
                        <button type="button"><?= $e ?></button>
                    <?php endforeach; ?>
                </div>
                <?php if ($canSend): ?>
                <div class="chat-composer" id="chatComposer">
                    <div class="chat-composer-free" id="chatComposerFree">
                        <button type="button" class="btn btn-light rounded-circle" id="btnEmoji" title="Emoji"><i class="far fa-smile"></i></button>
                        <button type="button" class="btn btn-light rounded-circle" id="btnAttach" title="Attach"><i class="fas fa-paperclip"></i></button>
                        <input type="file" id="chatFile" class="d-none" accept="image/*,application/pdf,video/*,audio/*">
                        <textarea id="chatInput" class="form-control" rows="1" placeholder="Type a message"></textarea>
                        <button type="button" class="btn btn-wa rounded-circle" id="btnChatSend" title="Send"><i class="fas fa-paper-plane"></i></button>
                    </div>
                    <div class="chat-composer-cta d-none" id="chatComposerTemplateCta">
                        <div class="chat-composer-cta-copy">
                            <strong>24h session closed</strong>
                            <span id="chatComposerLockCopy">Free-form WhatsApp messages need a recent customer reply. Send an approved template instead.</span>
                        </div>
                        <button type="button" class="btn btn-wa btn-sm" id="btnComposerTemplate">
                            <i class="fas fa-file-alt me-1"></i> Send template
                        </button>
                    </div>
                </div>
                <?php else: ?>
                <div class="chat-composer"><span class="text-muted">You do not have permission to send messages.</span></div>
                <?php endif; ?>
            </div>
        </div>
    </section>
</div>
</div>

<div class="offcanvas offcanvas-start chat-filter-canvas" tabindex="-1" id="chatFilterCanvas">
    <div class="offcanvas-header">
        <h5 class="offcanvas-title">Filters</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body">
        <div class="chat-filter-group">
            <label class="form-label small fw-semibold">General</label>
            <select class="form-select form-select-sm" id="chatFilterStatusSelect">
                <option value="open">Open</option>
                <option value="pending">Pending</option>
                <option value="chatbot">Chatbot</option>
                <option value="intervened">Intervened</option>
                <option value="resolved">Resolved</option>
                <option value="active">Active (24h window)</option>
                <option value="expired">Expired window</option>
                <option value="frt_exceeded">FRT exceeded</option>
                <option value="ctwa">CTWA ads</option>
                <option value="all">All chats</option>
            </select>
        </div>
        <div class="chat-filter-group">
            <label class="form-label small fw-semibold">Filter by status</label>
            <select class="form-select form-select-sm" id="chatFilterUnreadSelect">
                <option value="">All chats</option>
                <option value="unread">Unread only</option>
            </select>
        </div>
        <div class="chat-filter-group">
            <label class="form-label small fw-semibold">Filter by assignee</label>
            <select class="form-select form-select-sm" id="chatFilterAssigneeSelect">
                <option value="all">All assignees</option>
                <option value="mine">My chats</option>
                <option value="unassigned">Unassigned</option>
            </select>
        </div>
        <div class="form-check mt-3">
            <input class="form-check-input" type="checkbox" value="1" id="chatOldChatsFirst">
            <label class="form-check-label" for="chatOldChatsFirst">Show old chats first</label>
        </div>
    </div>
    <div class="offcanvas-footer p-3 pt-0">
        <button type="button" class="btn btn-wa w-100" id="btnApplyChatFilters">Apply filters</button>
    </div>
</div>

<div class="modal fade" id="templateReplyModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Send Template</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label">Template</label>
                <select id="templateSelect" class="form-select">
                    <option value="">Select…</option>
                    <?php foreach (($templates ?? []) as $tpl): ?>
                        <?php
                        $ht = strtolower((string) ($tpl['header_type'] ?? ''));
                        ?>
                        <option
                            value="<?= (int) $tpl['id'] ?>"
                            data-header-type="<?= esc($ht) ?>"
                            data-name="<?= esc((string) ($tpl['name'] ?? '')) ?>"
                        ><?= esc($tpl['name']) ?> (<?= esc($tpl['language'] ?? '') ?>)</option>
                    <?php endforeach; ?>
                </select>
                <div id="templateHeaderMediaWrap" class="mt-3 d-none">
                    <label class="form-label mb-1" id="templateHeaderMediaLabel">Header image</label>
                    <input type="file" id="templateHeaderMediaFile" class="form-control" accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/3gpp,application/pdf">
                    <div class="form-text" id="templateHeaderMediaHint">
                        This template needs media in the header. Meta’s approval sample cannot be reused — upload a file to send.
                    </div>
                    <div id="templateHeaderMediaStatus" class="small text-muted mt-1 d-none"></div>
                </div>
                <div id="templateVarsWrap" class="mt-3 d-none">
                    <label class="form-label mb-1">Variables</label>
                    <div id="templateVarsFields"></div>
                </div>
                <div class="form-text mt-2">Use templates when the 24-hour customer care window is closed. Create new ones under Templates.</div>
                <?php if (empty($templates)): ?>
                    <div class="alert alert-light border mt-3 mb-0 small">
                        No approved templates synced yet.
                        <a href="<?= site_url('templates') ?>">Sync</a> or
                        <a href="<?= site_url('templates/create') ?>">create</a> one.
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-wa" id="btnSendTemplate">Send</button>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= asset_url('assets/js/chat.js') ?>"></script>
<?= $this->endSection() ?>
