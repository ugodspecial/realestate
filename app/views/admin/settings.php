<?php /** Variables: $settings. */ $v = fn($k) => $settings[$k] ?? ''; ?>
<div class="admin-top">
  <div>
    <h1>Website settings</h1>
    <p>Contact details, social links, and display options. Changes appear on the website immediately.</p>
  </div>
</div>
<form method="post" action="<?= url('admin/settings') ?>">
<?= csrf_field() ?>
<div class="admin-card admin-form">
  <h2>Brand &amp; contact</h2>
  <p>Shown in the header, footer, and contact sections.</p>
  <div class="form-grid">
    <div class="field"><label for="sName">Website name</label><input id="sName" name="site_name" value="<?= e($v('site_name')) ?>" maxlength="120"></div>
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
