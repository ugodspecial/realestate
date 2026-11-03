<div class="admin-top">
  <div>
    <h1>Future agent portal</h1>
    <p>Prepared architecture for phase two — not active at launch.</p>
  </div>
</div>
<div class="roadmap-note">
  <h2>What is already prepared</h2>
  <p>The database already contains dormant tables for agent profiles, subscription packages, subscriptions, and verified payment events. Nothing here affects the live website: the agent portal flag is off, there are no public registration routes, and no payment integration is active.</p>
</div>
<div class="admin-cols" style="margin-top:23px">
  <div class="admin-card">
    <h2>Phase-two checklist</h2>
    <p>When the business is ready, a developer enables these in order:</p>
    <div class="permission-grid" style="grid-template-columns:1fr">
      <div class="permission-card"><h3>1 · Agent accounts</h3>Registration, login, verification, and agent dashboards reusing the existing roles and audit system.</div>
      <div class="permission-card"><h3>2 · Subscriptions</h3>Packages with listing limits, renewal, expiry, and grace handling on top of the prepared tables.</div>
      <div class="permission-card"><h3>3 · Payments</h3>Paystack and Flutterwave webhooks with signature verification before any listing goes live.</div>
      <div class="permission-card"><h3>4 · Moderation</h3>Agent submissions flow through the same approval queue as staff listings.</div>
    </div>
  </div>
  <div class="admin-card">
    <h2>Guardrails</h2>
    <p>Built-in rules that protect launch quality:</p>
    <div class="permission-grid" style="grid-template-columns:1fr">
      <div class="permission-card"><h3>No accidental launch</h3>Packages ship inactive; the portal flag must be deliberately enabled in configuration.</div>
      <div class="permission-card"><h3>No unpaid listings</h3>Expiry and limit checks will gate publishing, mirroring today’s approval flow.</div>
      <div class="permission-card"><h3>Full audit trail</h3>Every agent action will be logged exactly like staff actions.</div>
    </div>
  </div>
</div>
