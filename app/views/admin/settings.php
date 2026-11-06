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
  <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('check') ?> Save settings</button></div>
</div>
</form>
