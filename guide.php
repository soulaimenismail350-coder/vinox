<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/layout.php';

/* The explanation page. Public on purpose: someone deciding whether to make an
   account should be able to read exactly what the thing does first.

   Signed in, it renders inside the panel so the rail stays put - clicking a
   rail entry should never throw you out of the app. Signed out, it gets the
   marketing shell and a hero. */

$viewer = current_user();
$inApp  = $viewer !== null;

if ($inApp) {
    app_head('How to set up', 'guide', ['Processes' => 'dashboard.php']);
} else {
    mk_head('How to set up');
    ?>
<section class="hero" style="padding-bottom:clamp(32px,5vw,52px)">
  <div class="kicker"><i></i> Guide</div>
  <h1 class="display" style="font-size:clamp(32px,6vw,54px)">How to set up</h1>
  <p class="sub">
    What each part of the panel does, what the words on the screen mean, and
    what happens when you press each button.
  </p>
</section>

<div class="mk-body">
<?php
}
?>

  <section class="sec">
    <div class="sec-hd">
      <div>
        <div class="eyebrow">01 &middot; The shape of it</div>
        <h1 style="margin-top:8px">Two halves that never touch</h1>
      </div>
    </div>
    <div class="card">
      <div class="pad">
        <p class="lead" style="max-width:none">
          The <b>panel</b> is what you are looking at. It stores accounts,
          credits, keys and intent, and it shows state. It does not connect to
          any game server.
        </p>
        <p class="lead" style="max-width:none;margin-top:12px">
          The <b>worker</b> is a separate program. It asks the panel what is
          meant to be running, does that work, and posts back the in-game name,
          the level, the match count and a line for the log. It carries a
          shared token rather than a session, because it is not a browser.
        </p>
        <p class="lead" style="max-width:none;margin-top:12px">
          Keeping them apart is the whole point: you can close the tab, lose
          signal, or switch devices, and the run is unaffected. It also means
          nothing you do in the browser can hang waiting on a match.
        </p>
      </div>
    </div>
  </section>

  <section class="sec">
    <div class="sec-hd">
      <div>
        <div class="eyebrow">02 &middot; Accounts</div>
        <h1 style="margin-top:8px">Roles, expiry and suspension</h1>
      </div>
    </div>

    <div class="feats">
      <div class="feat">
        <h3>member</h3>
        <p>What everyone gets on signup. Can add, edit, control and remove
           their own processes, and redeem keys. Credits and access time come
           off the key used to sign up; roles are given, never asked for on
           the form.</p>
      </div>
      <div class="feat">
        <h3>seller</h3>
        <p>Everything a member can do, plus a panel of their own for the
           accounts they create and the keys they mint. Credits they hand out
           come off their own balance and cannot be taken back; a key reserves
           its whole promise up front and refunds only the part nobody
           redeemed. No suspending, no deleting.</p>
      </div>
      <div class="feat">
        <h3>admin</h3>
        <p>Everything a seller can do, over the accounts they created: set
           credits and expiry, change the username or password, suspend with a
           reason, delete, and mint keys. Credits are minted rather than paid
           for.</p>
      </div>
      <div class="feat">
        <h3>owner</h3>
        <p>The whole panel: every account, every process, every key. One owner
           cannot edit another - peers, deliberately, so the top of the site
           cannot be taken away by whoever signs in first.</p>
      </div>
      <div class="feat">
        <h3>Expiry</h3>
        <p>A clock running out. Optional - blank means the account never
           lapses. Checked on every page load, not only at sign-in.</p>
      </div>
      <div class="feat">
        <h3>Suspension</h3>
        <p>A decision about a person, and it carries a written reason you will
           still be able to read months later. Suspending an account also stops
           its processes, so it cannot keep a worker busy.</p>
      </div>
    </div>

    <div class="note info" style="margin-top:16px">
      <b>Why those two are separate</b>
      Folding a suspension into an expiry date would lose the reason, and the
      reason is the part you need later. An admin cannot suspend, demote or
      delete their own account, so the last way in cannot be closed by accident.
    </div>
  </section>

  <section class="sec">
    <div class="sec-hd">
      <div>
        <div class="eyebrow">03 &middot; Credits and keys</div>
        <h1 style="margin-top:8px">One credit, one slot</h1>
      </div>
    </div>
    <div class="card">
      <div class="pad">
        <p class="lead" style="max-width:none">
          Adding a process costs one credit. Removing it returns that credit, so
          you can try an account, swap it out, and lose nothing.
        </p>
        <p class="lead" style="max-width:none;margin-top:12px">
          Credits come from keys, and a key carries three things: how many
          credits each redemption gives, how many different people may redeem
          it, and how long each of them gets to keep the account. One person
          may use a given key once - enforced by a unique index in the database
          rather than a check in code, so two tabs submitting the same code at
          the same moment cannot both win.
        </p>
        <p class="lead" style="max-width:none;margin-top:12px">
          The lifetime runs from the moment <i>you</i> redeem, not from the day
          the key was made, so ten people sharing one code each get the full
          span out of it. Redeem a second key before the first runs out and the
          time is added on rather than replacing what is left.
        </p>
        <p class="lead" style="max-width:none;margin-top:12px">
          Signing up on this site needs a key: it is what the account is made
          of. When the time on it runs out that account is deleted, along with
          its processes and their logs. An account handed to you directly by an
          owner, an admin or a seller works the other way - its expiry is
          whatever they set, a key cannot overwrite it, and it is never deleted
          on a clock. Redeeming a key on such an account adds the credits and
          leaves the date alone.
        </p>
        <p class="lead" style="max-width:none;margin-top:12px">
          Key codes leave out O, 0, I and 1. These get read aloud and retyped,
          and that particular confusion turns straight into support work.
        </p>
      </div>
    </div>
  </section>

  <section class="sec" id="states">
    <div class="sec-hd">
      <div>
        <div class="eyebrow">04 &middot; Process states</div>
        <h1 style="margin-top:8px">What the dot is telling you</h1>
        <p class="lead">
          A blue dot means something is happening right now. Anything not blue
          is at rest. There are no other status colours, so the blue is
          readable at a glance from across a room.
        </p>
      </div>
    </div>

    <div class="card">
      <div class="tbl-wrap">
        <table>
          <thead>
            <tr><th>State</th><th>Meaning</th><th>What to do</th></tr>
          </thead>
          <tbody>
            <tr>
              <td data-label="State"><span class="status-wrap"><span class="dot idle"></span><span class="state idle">idle</span></span></td>
              <td data-label="Meaning">Created or stopped. Nothing is running.</td>
              <td data-label="Next">Press Start.</td>
            </tr>
            <tr>
              <td data-label="State"><span class="status-wrap"><span class="dot starting"></span><span class="state starting">starting</span></span></td>
              <td data-label="Meaning">You asked it to run. No worker has picked it up yet.</td>
              <td data-label="Next">Wait. Pressing Start again changes nothing.</td>
            </tr>
            <tr>
              <td data-label="State"><span class="status-wrap"><span class="dot running"></span><span class="state running">running</span></span></td>
              <td data-label="Meaning">A worker holds it and is playing matches.</td>
              <td data-label="Next">Watch the bar, or Stop.</td>
            </tr>
            <tr>
              <td data-label="State"><span class="status-wrap"><span class="dot stopping"></span><span class="state stopping">stopping</span></span></td>
              <td data-label="Meaning">Stop was asked for. A match already in flight finishes on its own.</td>
              <td data-label="Next">Wait for idle.</td>
            </tr>
            <tr>
              <td data-label="State"><span class="status-wrap"><span class="dot done"></span><span class="state done">done</span></span></td>
              <td data-label="Meaning">The match target was reached.</td>
              <td data-label="Next">Reset the counter, or raise the target.</td>
            </tr>
            <tr>
              <td data-label="State"><span class="status-wrap"><span class="dot error"></span><span class="state error">error</span></span></td>
              <td data-label="Meaning">The worker gave up. The reason is in the message and the log.</td>
              <td data-label="Next">Read the activity log, fix, Start again.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="note info" style="margin-top:16px">
      <b>Why starting and stopping exist at all</b>
      The worker cannot act instantly. Without these two states a click looks
      ignored, and the natural response to a button that did nothing is to press
      it again.
    </div>
  </section>

  <section class="sec">
    <div class="sec-hd">
      <div>
        <div class="eyebrow">05 &middot; Controls</div>
        <h1 style="margin-top:8px">Start, Stop, Restart, Reset, Remove</h1>
        <p class="lead">
          All five are on the dashboard card and on the process page, in every
          state. The one or two that would do nothing right now are greyed out
          and say why on hover, rather than disappearing - a button that
          vanishes between page loads looks like a fault.
        </p>
      </div>
    </div>
    <div class="steps">
      <div class="step">
        <div>
          <h3>Start</h3>
          <p>Marks the process as wanted. It goes to <code>starting</code> until
             a worker claims it.</p>
        </div>
      </div>
      <div class="step">
        <div>
          <h3>Stop</h3>
          <p>Marks it as no longer wanted. A match already in progress is
             allowed to finish rather than being cut off mid-way.</p>
        </div>
      </div>
      <div class="step">
        <div>
          <h3>Restart</h3>
          <p>Stop and Start in one press. This is what applies new settings to a
             session that is already live.</p>
        </div>
      </div>
      <div class="step">
        <div>
          <h3>Reset counter</h3>
          <p>Puts matches played back to zero without touching the credentials
             or the target. If the process is live it stops the run as well,
             and the confirmation says so.</p>
        </div>
      </div>
      <div class="step">
        <div>
          <h3>Remove</h3>
          <p>Deletes the process and its log, and returns the credit to your
             balance.</p>
        </div>
      </div>
    </div>
  </section>

  <section class="sec">
    <div class="sec-hd">
      <div>
        <div class="eyebrow">06 &middot; Settings</div>
        <h1 style="margin-top:8px">Changing a process while it runs</h1>
      </div>
    </div>
    <div class="card">
      <div class="pad">
        <p class="lead" style="max-width:none">
          Match count and the unlimited switch change from the dashboard card without
          opening anything. Credentials can only be changed on the process page,
          where there is room to see what you are replacing.
        </p>
        <p class="lead" style="max-width:none;margin-top:12px">
          If the process is live and something meaningful changed, you are
          asked whether to restart now. Both answers are honest: restarting
          kills a match in progress, and not restarting means the running
          session keeps the old settings - and the card says so rather than
          pretending the change took hold.
        </p>
        <p class="lead" style="max-width:none;margin-top:12px">
          A blank credential field means <b>leave it alone</b>. Otherwise opening
          the page to bump the match count would silently wipe the login.
        </p>
      </div>
    </div>
  </section>

  <section class="sec">
    <div class="sec-hd">
      <div>
        <div class="eyebrow">07 &middot; The design</div>
        <h1 style="margin-top:8px">Blue to follow, green to act</h1>
      </div>
    </div>
    <div class="card">
      <div class="pad">
        <p class="lead" style="max-width:none">
          Two accents, each with one job. <b>Blue</b> marks anything you can
          follow: a link, the page you are on, the credit balance you keep
          checking. <b>Green</b> marks the single thing on a view that does
          something - the submit button, a live process, a moving progress
          bar. <b>Red</b> is damage and failure, and appears nowhere else.
          Colour that means something can be read at a glance; colour
          everywhere means nothing anywhere.
        </p>
        <p class="lead" style="max-width:none;margin-top:12px">
          Dark is the default rather than a variant: the canvas is a deep
          slate, panels sit one step lighter, and inputs sit one step darker
          than the panel holding them, so depth comes from the surfaces
          instead of from shadows. Light arrives only if the machine asks for
          it, or if you pick it with the button in the top bar - and your
          choice is applied before the page paints, so navigating never
          flashes the wrong theme.
        </p>
        <p class="lead" style="max-width:none;margin-top:12px">
          Labels, buttons and headings are set in small uppercase so the
          chrome reads as chrome and the content reads as content. Monospace
          carries anything you might copy, retype or compare - keys, account
          ids, counters, timestamps - so a code never has to be squinted at.
        </p>
        <p class="lead" style="max-width:none;margin-top:12px">
          Layout is fluid from about 320px. The rail becomes a drawer, admin
          tables become stacked cards rather than a horizontal scroll that
          hides the actions column, and every surface is drawn in CSS - no
          images, no icon fonts - so it stays sharp at any device pixel ratio.
        </p>
      </div>
    </div>
  </section>

  <section class="sec">
    <div class="acts" style="margin-top:0">
      <?php if ($inApp): ?>
        <a class="btn blue lg" href="add.php">Add a process</a>
        <a class="btn line lg" href="redeem.php">Redeem a key</a>
      <?php else: ?>
        <a class="btn blue lg" href="register.php">Create an account</a>
        <a class="btn line lg" href="index.php#plans">See the plans</a>
      <?php endif; ?>
    </div>
  </section>

<?php
if ($inApp) {
    app_foot();
} else {
    echo '</div>';
    mk_foot();
}
?>
