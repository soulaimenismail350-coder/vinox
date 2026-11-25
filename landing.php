<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/layout.php';
mk_head('Level boosting for your private server');
?>
<section class="hero">
  <div class="kicker"><i></i> Levelling on your own server</div>
  <h1 class="display">FREE FIRE LEVEL <span class="thin">PRO</span></h1>
  <p class="sub">A control panel for levelling accounts on your own private server.</p>
  <div class="acts">
    <a class="btn blue lg" href="register.php">Create an account</a>
    <a class="btn line lg" href="guide.php">How it works</a>
  </div>
</section>
<div class="mk-body">
  <section class="sec">
    <div class="sec-hd">
      <div>
        <div class="eyebrow">What it is</div>
        <h1 style="margin-top:8px">A panel that stores intent</h1>
        <p class="lead">The panel never touches the game itself. It records what you want - this account, this many matches - and a worker process reads that intent, does the work, and reports back what happened.</p>
      </div>
    </div>
  </section>

  <section class="sec" id="plans">
    <div class="sec-hd">
      <div>
        <div class="eyebrow">Pricing</div>
        <h1 style="margin-top:8px">Choose your plan</h1>
        <p class="lead">
          You are buying a key. It carries the credits and the time, and the
          clock starts when you redeem it - not when it was bought.
        </p>
      </div>
    </div>

    <div class="plans">
      <div class="plan">
        <div class="pt">Basic</div>
        <div class="price">$1</div>
        <div class="per">24 hours of access</div>
        <ul>
          <li>1 credit, one process slot</li>
          <li>Guest login or access token</li>
          <li>Set how many matches to play</li>
          <li>Live progress, level and XP</li>
          <li>Add and remove processes freely</li>
        </ul>
        <a class="btn line block" href="register.php">Get started</a>
      </div>

      <div class="plan pick">
        <div class="pt">Most picked</div>
        <div class="price">$3.80</div>
        <div class="per">3 days of access</div>
        <ul>
          <li>Three full days on one key</li>
          <li>Everything in Basic</li>
          <li>Long runs without renewing daily</li>
          <li>Keep levelling overnight</li>
          <li>Best value per hour</li>
        </ul>
        <a class="btn blue block" href="register.php">Start now</a>
      </div>

      <div class="plan">
        <div class="pt">Custom</div>
        <div class="price">Ask</div>
        <div class="per">Your own terms</div>
        <ul>
          <li>Any number of credits</li>
          <li>Any length of access</li>
          <li>Your own batch of keys</li>
          <li>Reseller accounts</li>
          <li>Direct support</li>
        </ul>
        <a class="btn line block" href="guide.php">Read the guide</a>
      </div>
    </div>

    <div class="note info" style="margin-top:18px">
      <b>How a key works</b>
      Creating an account here needs one. It sets the credits you start with and
      how long the account lives, counted from the moment you redeem it. When
      that time runs out the account and its processes are removed.
    </div>
  </section>
</div>
<?php mk_foot(); ?>
