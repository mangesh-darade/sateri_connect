<?= $this->extend('layouts/platform') ?>

<?= $this->section('content') ?>
<?php
$cfg  = $cfg ?? ['url' => '', 'username' => '', 'has_password' => false, 'has_leads_key' => false];
$base = site_url('platform/voice-agent');
?>
<div id="vaPage" data-base="<?= esc($base, 'attr') ?>">

<section class="platform-card">
    <div class="platform-card-head">
        <div>
            <h2>AI voice agent</h2>
            <p>One calling server for every client. Clients switch calling on in their own Settings (consent confirmation and hourly limit stay per client); the address, key and everything below are set once here.</p>
        </div>
        <span class="platform-badge <?= $cfg['url'] !== '' && $cfg['has_leads_key'] ? 'platform-badge-ok' : 'platform-badge-warn' ?>"><?= $cfg['url'] !== '' && $cfg['has_leads_key'] ? 'Connected settings saved' : 'Not set up' ?></span>
    </div>

    <h3 class="platform-subhead">1. Connection</h3>
    <form id="vaConnForm" class="platform-form-grid" autocomplete="off">
        <div class="full">
            <label class="platform-label" for="vaUrl">Voice agent address</label>
            <input class="platform-input" id="vaUrl" name="url" value="<?= esc($cfg['url'], 'attr') ?>" placeholder="https://voice.yourdomain.com">
            <div class="platform-help">The public address of the voice agent API (the server that runs <code>uvicorn api.main:app</code>). It must be reachable from this server.</div>
        </div>
        <div>
            <label class="platform-label" for="vaUser">Dashboard username</label>
            <input class="platform-input" id="vaUser" name="username" value="<?= esc($cfg['username'], 'attr') ?>" autocomplete="off">
            <div class="platform-help">The voice agent platform admin login (<code>DASHBOARD_USER</code>). Used only to read and save the settings below.</div>
        </div>
        <div>
            <label class="platform-label" for="vaPass">Dashboard password</label>
            <input class="platform-input" id="vaPass" type="password" name="password" value="" placeholder="<?= $cfg['has_password'] ? '•••••••• saved (leave blank to keep)' : '' ?>" autocomplete="new-password">
        </div>
        <div class="full">
            <label class="platform-label" for="vaLeads">Leads API key</label>
            <input class="platform-input" id="vaLeads" type="password" name="leads_key" value="" placeholder="<?= $cfg['has_leads_key'] ? '•••••••• saved (leave blank to keep)' : 'Paste the existing LEADS_API_KEY, or use Create key below' ?>" autocomplete="new-password">
            <div class="platform-help">Sateri Connect sends this as <code>X-API-Key</code> when a client hands a lead to the voice agent. Clients with no key of their own use this one.</div>
        </div>
        <div class="full platform-actions">
            <button class="btn-pf btn-pf-primary" type="submit"><i class="fas fa-floppy-disk" aria-hidden="true"></i> Save connection</button>
            <button class="btn-pf" type="button" id="vaPing"><i class="fas fa-plug" aria-hidden="true"></i> Test connection</button>
            <button class="btn-pf" type="button" id="vaGenKey"><i class="fas fa-key" aria-hidden="true"></i> Create key and apply</button>
            <button class="btn-pf" type="button" id="vaLoad"><i class="fas fa-rotate" aria-hidden="true"></i> Load settings from the voice agent</button>
        </div>
    </form>
</section>

<div id="vaLoaded" hidden>
    <section class="platform-card">
        <div class="platform-card-head">
            <div>
                <h2>2. Agent script and voice</h2>
                <p>What the AI says and how it sounds. Saved on the voice agent.</p>
            </div>
        </div>
        <form id="vaAgentForm" class="platform-form-grid" autocomplete="off">
            <div>
                <label class="platform-label" for="vaAgName">Agent name</label>
                <input class="platform-input" id="vaAgName" name="name" maxlength="120">
            </div>
            <div>
                <label class="platform-label" for="vaAgLang">Language</label>
                <select class="platform-input" id="vaAgLang" name="language">
                    <option value="">Default</option>
                    <option value="auto">Auto (detect)</option>
                    <option value="en-IN">English (India)</option>
                    <option value="hi-IN">Hindi</option>
                    <option value="mr-IN">Marathi</option>
                </select>
            </div>
            <div class="full">
                <label class="platform-label" for="vaAgPrompt">Script (what the agent should do)</label>
                <textarea class="platform-input" id="vaAgPrompt" name="prompt" rows="9" maxlength="20000" placeholder="You are Sateri's sales assistant for Mango Pulp and Konkan Cashews. Greet the customer, ask which product they need, the quantity and city, then say the team will send a quotation. Keep replies short. Never quote a price you were not given."></textarea>
                <div class="platform-help">Leave it blank to use the voice agent's built-in sales script.</div>
            </div>
            <div>
                <label class="platform-label" for="vaAgStt">Speech to text</label>
                <select class="platform-input" id="vaAgStt" name="stt_provider"><option value="">Default</option><option value="deepgram">Deepgram</option><option value="sarvam">Sarvam</option></select>
            </div>
            <div>
                <label class="platform-label" for="vaAgTts">Text to speech</label>
                <select class="platform-input" id="vaAgTts" name="tts_provider"><option value="">Default</option><option value="deepgram">Deepgram</option><option value="sarvam">Sarvam</option></select>
            </div>
            <div>
                <label class="platform-label" for="vaAgSpeaker">Voice (optional)</label>
                <input class="platform-input" id="vaAgSpeaker" name="speaker" maxlength="60">
            </div>
            <div>
                <label class="platform-label" for="vaAgLlm">AI model (optional)</label>
                <input class="platform-input" id="vaAgLlm" name="llm_model" maxlength="120" placeholder="Leave blank for the default">
            </div>
            <div>
                <label class="platform-label" for="vaAgPhone">Calling number</label>
                <input class="platform-input" id="vaAgPhone" name="phone_number" maxlength="30" placeholder="+91XXXXXXXXXX">
            </div>
            <div>
                <label class="platform-label" for="vaAgHours">Calling hours</label>
                <input class="platform-input" id="vaAgHours" name="calling_hours" maxlength="11" placeholder="09:00-20:00">
                <div class="platform-help">Calls are only placed inside these hours (24 hour clock).</div>
            </div>
            <div>
                <label class="platform-label" for="vaAgCountry">Country</label>
                <input class="platform-input" id="vaAgCountry" name="country" maxlength="2" placeholder="IN">
            </div>
            <div>
                <label class="platform-label" for="vaAgTz">Time zone</label>
                <input class="platform-input" id="vaAgTz" name="timezone" maxlength="60" placeholder="Asia/Kolkata">
            </div>
            <div class="full">
                <label><input type="checkbox" id="vaAgRec" name="recording_enabled" value="1"> Record calls (needs the S3 settings below, and customers must be told)</label>
            </div>
            <div class="full platform-actions">
                <button class="btn-pf btn-pf-primary" type="submit"><i class="fas fa-floppy-disk" aria-hidden="true"></i> Save agent</button>
            </div>
        </form>
    </section>

    <section class="platform-card">
        <div class="platform-card-head">
            <div>
                <h2>3. Server settings</h2>
                <p>Phone line (SIP), Exotel, automation webhooks, lead timing and recording storage. Secrets are never shown: a saved one shows as set, and a blank box keeps it.</p>
            </div>
        </div>
        <form id="vaServerForm" autocomplete="off">
            <div id="vaServerFields"></div>
            <div class="platform-actions">
                <button class="btn-pf btn-pf-primary" type="submit"><i class="fas fa-floppy-disk" aria-hidden="true"></i> Save server settings</button>
                <button class="btn-pf" type="button" id="vaSip"><i class="fas fa-phone-volume" aria-hidden="true"></i> Create SIP trunks</button>
            </div>
        </form>
        <div class="platform-help" style="margin-top:0.75rem">
            LiveKit, Deepgram, Groq and Sarvam keys are read by the voice agent from its own server <code>.env</code> file and cannot be edited from here. Set them once when the voice agent is installed.
        </div>
    </section>
</div>

<section class="platform-card" id="vaClients">
    <div class="platform-card-head">
        <div>
            <h2>4. A client with its own script and do-not-call list</h2>
            <p>By default every client shares the voice agent above (one script, one do-not-call list). Give a client its own account when it needs a different script, calling hours or number. The client's address and key are saved in its Settings; calling stays off until the client turns it on.</p>
        </div>
    </div>
    <form id="vaClientForm" class="platform-form-grid" autocomplete="off">
        <div class="full">
            <label class="platform-label" for="vaClientPick">Client</label>
            <select class="platform-input" id="vaClientPick" name="client">
                <option value="">Choose a client</option>
                <?php foreach (($clients ?? []) as $c): ?>
                    <option value="<?= esc($c['key'], 'attr') ?>"><?= esc($c['name']) ?> (<?= esc($c['key']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="full platform-actions">
            <button class="btn-pf btn-pf-primary" type="submit"><i class="fas fa-user-plus" aria-hidden="true"></i> Create account and connect this client</button>
        </div>
    </form>
    <div id="vaClientResult" class="platform-help" style="margin-top:0.75rem" hidden></div>
    <div id="vaAccounts" class="platform-help" style="margin-top:0.75rem"></div>
</section>

</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="<?= asset_url('assets/js/platform-voice-agent.js') ?>"></script>
<?= $this->endSection() ?>
