<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/page-bootstrap.php';
$page = user_page_config(['key'=>'topup','title'=>'Top-Up','section_id'=>'topupSection','body_class'=>'user-topup-page','page_css'=>'topup-page.css','page_js'=>'topup-page.js','active_nav'=>'']);
user_page_begin($page);
?>
      <section id="topupSection" class="page-section active">
        <div class="wizard-card">
          <div class="section-head">
            <div>
              <h3 class="section-title">Create Topup</h3>
              <p class="section-sub">Simple step-by-step mobile topup request</p>
            </div>
          </div>

          <div class="wizard-progress">
            <div id="wizardPill1" class="wizard-pill active">Number</div>
            <div id="wizardPill2" class="wizard-pill">Operator</div>
            <div id="wizardPill3" class="wizard-pill">Amount</div>
            <div id="wizardPill4" class="wizard-pill">PIN</div>
            <div id="wizardPill5" class="wizard-pill">Confirm</div>
          </div>

          <div id="wizardStep1" class="wizard-step active">
            <div class="wizard-step-title">Enter Topup Number</div>
            <div class="wizard-step-sub">Write the customer mobile number</div>

            <input id="wizardTopupNumber" class="wizard-big-input" type="tel" inputmode="numeric" placeholder="01712345678">

            <div class="wizard-actions">
              <button id="wizardNext1" class="btn green" type="button">Next</button>
            </div>
          </div>

          <div id="wizardStep2" class="wizard-step">
            <div class="wizard-step-title">Select Operator</div>
            <div class="wizard-step-sub">Choose the correct mobile operator</div>

            <div class="choice-grid">
              <button type="button" class="choice-btn operator-choice" data-operator="GP">
                Grameenphone
                <small>GP</small>
              </button>

              <button type="button" class="choice-btn operator-choice" data-operator="ROBI">
                Robi
                <small>ROBI</small>
              </button>

              <button type="button" class="choice-btn operator-choice" data-operator="AIRTEL">
                Airtel
                <small>AIRTEL</small>
              </button>

              <button type="button" class="choice-btn operator-choice" data-operator="BL">
                Banglalink
                <small>BL</small>
              </button>

              <button type="button" class="choice-btn operator-choice" data-operator="TT">
                Teletalk
                <small>TT</small>
              </button>
            </div>

            <div class="wizard-actions">
              <button id="wizardBack2" class="btn ghost" type="button">Back</button>
              <button id="wizardNext2" class="btn green" type="button">Next</button>
            </div>
          </div>

          <div id="wizardStep3" class="wizard-step">
            <div class="wizard-step-title">Enter Amount</div>
            <div class="wizard-step-sub">Select quick amount or enter manually</div>

            <div class="choice-grid choice-grid-small-gap">
              <button type="button" class="choice-btn amount-choice" data-amount="20">BDT 20</button>
              <button type="button" class="choice-btn amount-choice" data-amount="30">BDT 30</button>
              <button type="button" class="choice-btn amount-choice" data-amount="50">BDT 50</button>
              <button type="button" class="choice-btn amount-choice" data-amount="100">BDT 100</button>
            </div>

            <input id="wizardAmount" class="wizard-big-input wizard-big-input-top" type="number" inputmode="decimal" step="0.01" min="1" placeholder="Enter amount">

            <div class="wizard-actions">
              <button id="wizardBack3" class="btn ghost" type="button">Back</button>
              <button id="wizardNext3" class="btn green" type="button">Next</button>
            </div>
          </div>

          <div id="wizardStep4" class="wizard-step">
            <div class="wizard-step-title">Enter Transaction PIN</div>
            <div class="wizard-step-sub">Your PIN is required to confirm this request</div>

            <input id="wizardPin" class="wizard-big-input" type="password" inputmode="numeric" placeholder="Enter PIN">

            <div class="wizard-actions">
              <button id="wizardBack4" class="btn ghost" type="button">Back</button>
              <button id="wizardNext4" class="btn green" type="button">Next</button>
            </div>
          </div>

          <div id="wizardStep5" class="wizard-step">
            <div class="wizard-step-title">Confirm Topup</div>
            <div class="wizard-step-sub">Check all information before submit</div>

            <div class="review-grid">
              <div class="review-box"><label>Number</label><strong id="reviewNumber">-</strong></div>
              <div class="review-box"><label>Operator</label><strong id="reviewOperator">-</strong></div>
              <div class="review-box"><label>Amount</label><strong id="reviewAmount">-</strong></div>
              <div class="review-box"><label>PIN</label><strong id="reviewPin">â€¢â€¢â€¢â€¢</strong></div>
            </div>

            <div class="wizard-actions">
              <button id="wizardBack5" class="btn ghost" type="button">Back</button>
              <button id="wizardConfirmBtn" class="btn green" type="button">Confirm Topup</button>
            </div>
          </div>

          <div class="result-box">
            <div id="topupResult" class="result-empty">No topup created yet.</div>
          </div>
        </div>
      </section>
<?php user_page_end($page); ?>
