<?php /** Variables: $settings. */ $v = fn($k) => $settings[$k] ?? ''; ?>
<div class="admin-top">
  <div>
    <h1>Website settings</h1>
    <p>Branding, contact details, social links, and display options. Changes appear on the website immediately.</p>
  </div>
</div>
<form method="post" action="<?= url('admin/settings') ?>" enctype="multipart/form-data">
<?= csrf_field() ?>
<div class="admin-card admin-form">
  <h2>Branding</h2>
  <p>The website name and logo appear in the header, footer, and on the staff sign-in page.</p>
  <div class="form-grid">
    <div class="field"><label for="sName">Website name</label><input id="sName" name="brand_name" value="<?= e($v('brand_name') ?: $v('site_name')) ?>" maxlength="120" required></div>
    <div class="field"><label for="sBrandTag">Brand line <small>(small text under the name)</small></label><input id="sBrandTag" name="brand_tagline" value="<?= e($v('brand_tagline') ?: 'REAL ESTATE') ?>" maxlength="60"></div>
    <div class="field full">
      <label for="sLogo">Logo <small>(SVG, PNG, JPG, or WebP · max 2 MB)</small></label>
      <?php $currentLogo = logo_url(); ?>
      <div class="logo-preview<?= $currentLogo ? '' : ' logo-preview-empty' ?>">
        <?php if ($currentLogo): ?>
          <img src="<?= e($currentLogo) ?>" alt="Current logo" width="180" height="52">
          <label class="checkbox-label logo-remove"><input type="checkbox" name="remove_logo" value="1"> Remove this logo and use the default mark</label>
        <?php else: ?>
          <span class="logo-preview-mark"><?= brand_mark() ?></span>
          <span class="logo-preview-note">No logo uploaded — the default cedar mark is shown. Upload a logo to replace it.</span>
        <?php endif; ?>
      </div>
      <input id="sLogo" type="file" name="brand_logo" accept=".svg,.png,.jpg,.jpeg,.webp,image/svg+xml,image/png,image/jpeg,image/webp">
    </div>
  </div>
</div>
<div class="admin-card admin-form">
  <h2>Brand &amp; contact</h2>
  <p>Shown in the header, footer, and contact sections.</p>
  <div class="form-grid">
    <div class="field"><label for="sTag">Tagline</label><input id="sTag" name="tagline" value="<?= e($v('tagline')) ?>" maxlength="200"></div>
    <div class="field"><label for="sPhone">Phone</label><input id="sPhone" name="phone" value="<?= e($v('phone')) ?>" maxlength="40"></div>
    <div class="field"><label for="sWa">WhatsApp number <small>(digits with country code)</small></label><input id="sWa" name="whatsapp" value="<?= e($v('whatsapp')) ?>" maxlength="30" placeholder="e.g. 2348012345678"></div>
    <div class="field"><label for="sEmail">Public email</label><input id="sEmail" type="email" name="email" value="<?= e($v('email')) ?>" maxlength="190"></div>
    <div class="field"><label for="sNotify">Enquiry notifications email</label><input id="sNotify" type="email" name="notify_email" value="<?= e($v('notify_email')) ?>" maxlength="190"></div>
    <div class="field full"><label for="sAddr">Office address</label><input id="sAddr" name="address" value="<?= e($v('address')) ?>" maxlength="255"></div>
    <div class="field full">
      <label for="sHours">Office hours <small>(one line per entry, shown as “When we’re here” on the contact page)</small></label>
      <textarea id="sHours" name="office_hours" rows="4" maxlength="500" placeholder="Monday–Friday: 8:30am–5:30pm&#10;Saturday: 10:00am–3:00pm"><?= e(str_replace('|', "\n", $v('office_hours'))) ?></textarea>
      <p class="field-hint">Each line is shown as a separate opening time. Leave empty to hide the opening hours.</p>
    </div>
  </div>
</div>
<div class="admin-card admin-form">
  <h2>Social &amp; display</h2>
  <p>Leave a social field blank to hide that link.</p>
  <div class="form-grid">
    <div class="field"><label for="sFb">Facebook URL</label><input id="sFb" name="facebook" value="<?= e($v('facebook')) ?>" maxlength="255"></div>
    <div class="field"><label for="sIg">Instagram URL</label><input id="sIg" name="instagram" value="<?= e($v('instagram')) ?>" maxlength="255"></div>
    <div class="field"><label for="sTw">X (Twitter) URL</label><input id="sTw" name="twitter" value="<?= e($v('twitter')) ?>" maxlength="255"></div>
    <div class="field"><label for="sLi">LinkedIn URL</label><input id="sLi" name="linkedin" value="<?= e($v('linkedin')) ?>" maxlength="255"></div>
    <div class="field"><label for="sPer">Listings per page</label><input id="sPer" type="number" name="listings_per_page" min="3" max="48" value="<?= e($v('listings_per_page') ?: '9') ?>"></div>
    <div class="field"><label for="sMap">Map embed code <small>(optional)</small></label><input id="sMap" name="map_embed" value="<?= e($v('map_embed')) ?>" maxlength="4000" placeholder="Paste a map share URL or embed code"></div>
  </div>
</div>
<div class="admin-card admin-form">
  <h2>Search engines</h2>
  <p>Let Google and Bing list the site. Leave a verification code blank if you have not set that up yet.</p>
  <div class="form-grid">
    <div class="field full">
      <label for="sGv">Google Search Console verification <small>(optional)</small></label>
      <input id="sGv" name="google_verification" value="<?= e($v('google_verification')) ?>" maxlength="120" placeholder="Paste the content value from the verification meta tag">
      <p class="field-hint">Search Console gives you the meta tag value directly. Only the code is needed — the tag itself is generated for you.</p>
    </div>
    <div class="field full">
      <label for="sBv">Bing Webmaster verification <small>(optional)</small></label>
      <input id="sBv" name="bing_verification" value="<?= e($v('bing_verification')) ?>" maxlength="120" placeholder="Paste the msvalidate.01 value">
    </div>
  </div>
  <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('check') ?> Save settings</button></div>
</div>
</form>
<?php /* Variables: $mail (enabled, describe, problems, to). A separate form: forms cannot nest. */ ?>
<form method="post" action="<?= url('admin/settings') ?>">
<?= csrf_field() ?>
<input type="hidden" name="action" value="test-mail">
<div class="admin-card admin-form" id="email-delivery">
  <h2>Email delivery</h2>
  <p>Enquiry alerts and staff password-reset links are sent by email.</p>
  <p><strong>Status:</strong> <?= $mail['enabled'] ? 'on, sending through ' . e($mail['describe']) . '.' : 'off. The website sends no email until it is switched on (see docs/EMAIL.md).' ?></p>
  <?php foreach ($mail['problems'] as $problem): ?>
    <p class="field-hint"><?= icon('alert') ?> <?= e($problem) ?></p>
  <?php endforeach; ?>
  <?php if ($mail['enabled']): ?>
    <p>Check that email really works from this server: the button sends one short message to your own address, <strong><?= e($mail['to']) ?></strong>, and shows the mail server’s answer in plain words if it cannot.</p>
    <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('mail') ?> Send a test email to me</button></div>
  <?php endif; ?>
</div>
</form>
<?php /* Variables: $mail['oauth'] (null, or label, flow, mailbox, connected, account, connected_at, client_id, device, note). Its own form: the one above is closed. */ ?>
<?php if (!empty($mail['oauth'])): $oauth = $mail['oauth']; ?>
<form method="post" action="<?= url('admin/settings') ?>">
<?= csrf_field() ?>
<div class="admin-card admin-form" id="mail-oauth">
  <h2><?= e($oauth['label']) ?> sign-in</h2>
  <?php if (!empty($oauth['note']['message'])): /* the answer to the button just pressed: here, where the page landed, not at the top */ ?>
    <div class="notice<?= empty($oauth['note']['ok']) ? ' notice-error' : '' ?>" role="status"><?= icon(empty($oauth['note']['ok']) ? 'alert' : 'check') ?><div><?= e($oauth['note']['message']) ?></div></div>
  <?php endif; ?>
  <?php if (!$oauth['supported']): ?>
    <p>Email is set to sign in with OAuth, but that provider is not one this website knows. See the problems listed under Email delivery.</p>
  <?php elseif ($oauth['flow'] === 'app'): ?>
    <p>No password is used. The website signs in to <?= e($oauth['label']) ?> as an app (client ID <code><?= e($oauth['client_id']) ?></code>) and sends email as <strong><?= e($oauth['mailbox'] !== '' ? $oauth['mailbox'] : 'the mailbox in MAIL_USERNAME') ?></strong>. Nobody has to sign in, so there is nothing to connect here: use <em>Send a test email to me</em> above to check it.</p>
  <?php elseif ($oauth['device']): $device = $oauth['device']; ?>
    <p>Connecting <?= e($oauth['label']) ?> takes one minute and is done with the mailbox that should send the website's email.</p>
    <ol class="oauth-steps">
      <li>Open <a href="<?= e($device['verification_uri']) ?>" target="_blank" rel="noopener noreferrer"><?= e($device['verification_uri']) ?></a>.</li>
      <li>Type this code: <span class="oauth-code" aria-label="Code <?= e(implode(' ', str_split($device['user_code']))) ?>"><?= e($device['user_code']) ?></span></li>
      <li>Sign in as the mailbox that should send the email, and accept.</li>
      <li>Come back here and press <strong>I have signed in</strong>.</li>
    </ol>
    <?php if ($device['checked']): ?><p class="field-hint"><?= icon('alert') ?> <?= e($oauth['label']) ?> has not seen the sign-in yet. Finish it on its page, then press the button again.</p><?php endif; ?>
    <p class="field-hint">The code works for about <?= (int) $device['minutes_left'] ?> more minute<?= $device['minutes_left'] === 1 ? '' : 's' ?>.</p>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit" name="action" value="oauth-check"><?= icon('check') ?> I have signed in</button>
      <button class="btn btn-outline" type="submit" name="action" value="oauth-cancel">Cancel</button>
    </div>
  <?php elseif ($oauth['connected']): ?>
    <p><strong>Connected</strong> as <strong><?= e($oauth['account']) ?></strong><?= $oauth['connected_at'] !== '' ? ' since ' . e(date('j F Y', (int) strtotime($oauth['connected_at']))) : '' ?>. The website keeps the sign-in, not a password. It lasts as long as the website sends email at least every few weeks, or until the account's password is changed or the connection is removed in Microsoft: then press Connect again.</p>
    <div class="form-actions">
      <button class="btn btn-outline" type="submit" name="action" value="oauth-connect">Connect again</button>
      <button class="btn btn-outline" type="submit" name="action" value="oauth-disconnect">Disconnect</button>
    </div>
  <?php else: ?>
    <p><strong>Not connected.</strong> The website cannot send email through <?= e($oauth['label']) ?> until the mailbox that should send it has signed in once. No password is typed here or stored.</p>
    <div class="form-actions"><button class="btn btn-primary" type="submit" name="action" value="oauth-connect"><?= icon('mail') ?> Connect <?= e($oauth['label']) ?></button></div>
  <?php endif; ?>
</div>
</form>
<?php endif; ?>
