<?php
/**
 * Commitment-fee letter PDF for a pre-auction enquiry, in the Auction Hub invoice layout
 * (same canvas as reg_invoice_pdf.php). Attached to the letter email; the client confirms
 * it online before any invoice is issued.
 */
require_once __DIR__ . '/reg_invoice_pdf.php';

/** Shared header: navy bar, logo, company lines, document title on the right. */
function ahgPdfHeader(AhgPdf $p, string $title): void {
    $navy = '#0d2b7b';
    $site = defined('CANONICAL_URL') ? preg_replace('~^https?://(www\.)?~', '', CANONICAL_URL) : COMPANY_WEBSITE;
    $p->rect(12, 7, 186, 2.2, $navy, null, 1);
    $tx = 12;
    if ($p->hasLogo()) { $p->logo(12, 15, 24); $tx = 41; }
    $p->text($tx, 22, mb_strtoupper(COMPANY_TRADING_AS), 20, true, $navy);
    $p->text($tx, 27.5, COMPANY_ADDRESS, 7.6);
    $p->text($tx, 31.5, str_replace(' ', '', COMPANY_PHONE) . '  |  ' . COMPANY_EMAIL . '  |  ' . $site, 7.6);
    $p->text($tx, 35.5, 'Reg: ' . COMPANY_REG_NO . '  |  SARS Tax No: ' . COMPANY_SARS_NO . '  |  Reg Date: ' . COMPANY_REG_DATE, 7.6);
    $p->text(198, 20, $title, 12.5, true, $navy, 'R');
    $p->line(12, 43, 198, 43, '#d9dfe9');
}

/** The commitment-fee letter PDF for one pre-auction application. */
function paTermsLetterPdf(array $app): string {
    $navy = '#0d2b7b'; $muted = '#7a8499'; $line = '#d9dfe9'; $ink = '#222222';
    $d     = $app['details'] ?? [];
    $price = (float)$app['price'];
    $pct   = (int)($d['offer_pct'] ?? $d['usual_pct'] ?? paBandPercent($price));
    $fee   = round($price * $pct / 100, 2);
    $bal   = round($price - $fee, 2);
    $usual = paBandPercent($price);
    $bank  = (string)($d['bank']['name'] ?? '');
    $R     = fn($n) => 'R' . number_format((float)$n, 2, '.', ',');
    $site  = defined('CANONICAL_URL') ? preg_replace('~^https?://(www\.)?~', '', CANONICAL_URL) : COMPANY_WEBSITE;
    $confirm = APP_URL . '/confirm_terms.php?t=' . $app['token'];

    $p = new AhgPdf();
    $p->setLogo(regLogoJpeg());
    $p->addPage();
    ahgPdfHeader($p, 'COMMITMENT FEE LETTER');

    // Prepared for / letter info
    $p->label(12, 49, 'Prepared for');
    $p->text(12, 56, (string)$app['name'], 12.5, true);
    $by = 62;
    foreach (['Email: ' . ($app['email'] ?? ''), 'Tel: ' . ($app['phone'] ?? ''), $bank !== '' ? 'Bank: ' . $bank : ''] as $l) {
        if (trim(preg_replace('/^\w+: /', '', $l)) === '') continue;
        $p->text(12, $by, $l, 8.6); $by += 5.3;
    }
    $info = [['Reference', finRef($app)], ['Letter date', date('d M Y')], ['Status', 'Awaiting your confirmation']];
    foreach ($info as $i => [$k, $v]) {
        $p->label(115, 49 + $i * 5.3, $k);
        $p->text(198, 49 + $i * 5.3, $v, 8.6, true, $ink, 'R');
    }
    $p->rect(115, 66, 83, 15, '#eef2f8', $line, 3, 0.6);
    $p->label(156.5, 71, 'Commitment fee (' . $pct . '%)', $muted, 'C');
    $p->text(156.5, 78, $R($fee), 15, true, $navy, 'C');

    // Vehicle bar and figures
    $p->rect(12, 86, 186, 8, $navy, null, 3);
    $p->label(17, 91.2, 'Vehicle', '#ffffff');
    $p->label(193, 91.2, 'Amount (ZAR)', '#ffffff', 'R');
    $p->text(17, 100.5, (string)$app['vehicle'], 11.3, true);
    $p->text(193, 100.5, $R($price), 9.8, true, $ink, 'R');
    $p->text(17, 105.5, 'Vehicle price, sold voetstoots and only while it is advertised.', 7.6, false, $muted);

    $p->text(108, 116, 'Vehicle price', 8.6, false, '#6b7385');            $p->text(198, 116, $R($price), 8.6, true, $ink, 'R');
    $p->line(108, 119, 198, 119, $line, 0.6);
    $p->text(108, 123.5, 'Commitment fee you offered (' . $pct . '%)', 8.6, false, '#6b7385'); $p->text(198, 123.5, $R($fee), 8.6, true, $ink, 'R');
    $p->line(108, 130, 198, 130, $navy, 1.6);
    $p->text(108, 136.5, 'BALANCE ON COLLECTION', 8.6, true, $navy);   $p->text(198, 137, $R($bal), 15, true, $navy, 'R');

    $p->label(12, 114, 'About the percentage');
    $note = 'The usual commitment fee for a vehicle at this price is ' . $usual . '%. Offers from 30% to 50% are considered. Our office confirms the final percentage on your invoice; if it differs from your offer, the invoice shows the final figure.';
    foreach (AhgPdf::wrap($note, 8.3, false, 86) as $i => $l) $p->text(12, 119 + $i * 4.5, $l, 8.3);

    // Body text, flowing onto page 2 when needed
    $y = 148;
    $newPage = function () use ($p, &$y, $navy) {
        $p->addPage();
        $p->rect(12, 7, 186, 2.2, $navy, null, 1);
        $y = 18;
    };
    $need = function (float $h) use (&$y, $newPage) { if ($y + $h > 284) $newPage(); };
    $heading = function (string $t) use ($p, &$y, $need, $navy) {
        $need(14);
        $p->text(12, $y, mb_strtoupper($t), 11, true, $navy);
        $y += 6.5;
    };
    $lh = 8.6 * 1.55 / 2.834646;
    $para = function (string $t) use ($p, &$y, $need, $lh) {
        $lines = AhgPdf::wrap($t, 8.6, false, 186);
        $need(count($lines) * $lh);
        foreach ($lines as $l) { $p->text(12, $y, $l, 8.6); $y += $lh; }
        $y += 2.5;
    };
    $bullets = function (array $items) use ($p, &$y, $need, $lh, $navy) {
        foreach ($items as $i => [$lead, $t]) {
            $leadW = AhgPdf::width($lead . ' ', 8.6, true) / 2.834646;
            $lines = AhgPdf::wrap($t, 8.6, false, 178, 178 - $leadW);
            $need(count($lines) * $lh + 1.5);
            $p->rect(13, $y - 3.4, 4.2, 4.2, $navy, null, 2.1);
            $p->text(15.1, $y - 0.4, (string)($i + 1), 6.3, true, '#ffffff', 'C');
            $p->text(20, $y, $lead, 8.6, true);
            foreach ($lines as $li => $l) { $p->text($li === 0 ? 20 + $leadW : 20, $y, $l, 8.6); $y += $lh; }
            $y += 1.5;
        }
        $y += 1.5;
    };

    $heading('What the commitment fee is for');
    $para('It reserves the vehicle for you. Once your commitment fee is paid and verified, the vehicle is marked Presold under your name with the financial institution, and no other buyer can bid on or access it.');

    $heading('What it means for you');
    $points = [
        ['Final percentage.', 'Our office confirms it on your proforma invoice, issued only after you confirm this letter.'],
        ['24-hour invoice.', 'The invoice is valid for 24 hours. If the fee is not paid in that time, the vehicle may be sold to another buyer.'],
        ['Buyer Code.', 'Once the fee is verified you receive your Buyer Code. You need it to come to our premises.'],
        ['Test drive and inspection.', 'You have 1 hour 30 minutes to test drive and inspect the vehicle. You may bring your own mechanic. The full DEKRA report can be viewed at our office; we do not send it by WhatsApp or email.'],
        ['Your final decision.', 'Once you are happy with the vehicle, you sign off your final decision: pay the outstanding balance and proceed, switch to your alternative vehicle, or cancel for a refund.'],
        ['Refunds.', 'If you cancel, a refund is paid to the account you paid from, less ' . $R(PA_REFUND_FEE) . ' admin fee and bank charges.'],
    ];
    if (!empty($d['alt']['title'])) $points[] = ['Alternative vehicle.', 'You nominated ' . $d['alt']['title'] . '. You can switch to it after your inspection.'];
    $points[] = ['Voetstoots.', 'Vehicles are sold voetstoots, and each one is only available while it is advertised.'];
    $bullets($points);

    // Confirm box
    $need(30);
    $p->rect(12, $y, 186, 25, '#f3f6fa', $line, 3, 0.6);
    $p->label(17, $y + 5.5, 'Next step: confirm you understand');
    $p->text(17, $y + 11.5, 'Open the link below and tap "Confirm and request my invoice". We issue your invoice only after you confirm.', 8.6);
    $p->text(17, $y + 17, $confirm, 8.2, true, $navy);
    $p->text(17, $y + 22, 'Not ready to proceed? You are welcome to register and bid at auction instead: ' . APP_URL . '/register-to-bid.php', 7.6, false, $muted);
    $y += 33;

    // Company details
    $need(70);
    $heading('Verify our company details');
    $p->text(12, $y, 'Check these details against the CIPC and SARS records. We never ask you to pay into a different account.', 8.2, false, $muted);
    $y += 5;
    $rows = [
        ['Registered name', COMPANY_REGISTERED_NAME . ' (Pty) Ltd, trading as ' . COMPANY_TRADING_AS],
        ['Registration no.', COMPANY_REG_NO . ' (registered ' . COMPANY_REG_DATE . ')'],
        ['SARS tax no.', COMPANY_SARS_NO],
        ['Address', COMPANY_ADDRESS],
        ['Phone', COMPANY_PHONE . ' / ' . COMPANY_PHONE2],
        ['WhatsApp', COMPANY_WHATSAPP],
        ['Email', COMPANY_EMAIL],
        ['Website', $site],
    ];
    foreach ($rows as [$k, $v]) {
        $p->text(12, $y + 4, $k, 8.6, false, '#6b7385');
        $p->text(60, $y + 4, $v, 8.6, true);
        $p->line(12, $y + 6.2, 198, $y + 6.2, $line, 0.5);
        $y += 6.8;
    }
    $y += 8;
    $need(10);
    $p->text(105, $y, 'This letter is not an invoice. No payment is due until you confirm and receive your invoice.', 8, false, $muted, 'C');
    $p->text(105, $y + 4.2, COMPANY_TRADING_AS . ' · ' . $site, 8, false, $muted, 'C');

    return $p->output();
}

/** Saves the letter in the application's private folder and returns the path ('' on failure). */
function paTermsLetterFile(array $app): string {
    try {
        $path = finUploadDir((int)$app['id']) . 'commitment_fee_letter_' . preg_replace('/[^A-Za-z0-9-]/', '', finRef($app)) . '.pdf';
        return @file_put_contents($path, paTermsLetterPdf($app)) ? $path : '';
    } catch (Throwable $e) {
        error_log('[AHG] commitment letter PDF: ' . $e->getMessage());
        return '';
    }
}

// ── Pre-auction: Letter of Decline ───────────────────────────

/** Reasons the office can tick when declining a pre-auction request. */
const PA_DECLINE_REASONS = [
    'bid'         => 'The vehicle received a bid before the commitment fee was paid.',
    'other_buyer' => 'Another buyer\'s commitment fee on this vehicle was paid first.',
    'institution' => 'The financial institution holding the vehicle did not accept a sale before the auction.',
    'percentage'  => 'The commitment percentage offered was below what the financial institution requires on this vehicle.',
    'withdrawn'   => 'The vehicle has been withdrawn from sale.',
];

/** The apologetic Letter of Decline (PDF) for a pre-auction request: what they asked for, why, and what they can do next. */
function paDeclineLetterPdf(array $app, array $reasons, string $note, bool $invoiced): string {
    $navy = '#0d2b7b'; $muted = '#7a8499'; $line = '#d9dfe9'; $ink = '#222222';
    $d     = $app['details'] ?? [];
    $price = (float)$app['price'];
    $pct   = (int)($d['invoice_pct'] ?? $d['offer_pct'] ?? $d['usual_pct'] ?? paBandPercent($price));
    $fee   = round($price * $pct / 100, 2);
    $R     = fn($n) => 'R' . number_format((float)$n, 2, '.', ',');
    $first = ucfirst(explode(' ', trim((string)$app['name']))[0] ?: 'Client');
    $site  = defined('CANONICAL_URL') ? preg_replace('~^https?://(www\.)?~', '', CANONICAL_URL) : COMPANY_WEBSITE;

    $p = new AhgPdf();
    $p->setLogo(regLogoJpeg());
    $p->addPage();
    ahgPdfHeader($p, 'LETTER OF DECLINE');

    // To / refs
    $p->label(12, 49, 'To');
    $p->text(12, 56, (string)$app['name'], 12.5, true);
    $by = 62;
    foreach (array_filter([(string)($app['email'] ?? ''), (string)($app['phone'] ?? '')]) as $l) { $p->text(12, $by, $l, 8.6); $by += 5.3; }
    foreach ([['Reference', finRef($app)], ['Date', date('d M Y')], ['Requested on', date('d M Y', strtotime((string)($app['created_at'] ?? 'now')))]] as $i => [$k, $v]) {
        $p->label(115, 49 + $i * 5.3, $k);
        $p->text(198, 49 + $i * 5.3, $v, 8.6, true, $ink, 'R');
    }

    // Flowing body
    $y = 80;
    $lh = 8.6 * 1.55 / 2.834646;
    $newPage = function () use ($p, &$y, $navy) { $p->addPage(); $p->rect(12, 7, 186, 2.2, $navy, null, 1); $y = 18; };
    $need = function (float $h) use (&$y, $newPage) { if ($y + $h > 284) $newPage(); };
    $para = function (string $t, bool $bold = false) use ($p, &$y, $need, $lh) {
        $lines = AhgPdf::wrap($t, 8.6, $bold, 186);
        $need(count($lines) * $lh);
        foreach ($lines as $l) { $p->text(12, $y, $l, 8.6, $bold); $y += $lh; }
        $y += 2.5;
    };
    $heading = function (string $t) use ($p, &$y, $need, $navy) { $need(14); $y += 2; $p->text(12, $y, mb_strtoupper($t), 11, true, $navy); $y += 6.5; };
    $bullets = function (array $items) use ($p, &$y, $need, $lh, $navy) {
        foreach ($items as [$lead, $t]) {
            $leadW = $lead === '' ? 0 : AhgPdf::width($lead . ' ', 8.6, true) / 2.834646;
            $lines = AhgPdf::wrap($t, 8.6, false, 178, 178 - $leadW);
            $need(count($lines) * $lh + 1.5);
            $p->rect(13.6, $y - 2.6, 2.4, 2.4, $navy, null, 1.2);
            if ($lead !== '') $p->text(20, $y, $lead, 8.6, true);
            foreach ($lines as $li => $l) { $p->text($li === 0 ? 20 + $leadW : 20, $y, $l, 8.6); $y += $lh; }
            $y += 1.5;
        }
        $y += 1.5;
    };

    $p->text(12, $y, 'Dear ' . $first . ',', 9.5, true); $y += 7;
    $p->text(12, $y, 'RE: PRE-AUCTION REQUEST — ' . mb_strtoupper((string)$app['vehicle']), 9.5, true, $navy); $y += 8;
    $para('Thank you for choosing ' . COMPANY_TRADING_AS . ' and for your interest in this vehicle. We are sorry to let you know that we are unable to approve your pre-auction request at this time. We understand this is disappointing, and we sincerely apologise for any inconvenience.');

    // Request table
    $need(32);
    $p->rect(12, $y, 186, 7, $navy, null, 2.5);
    $p->label(16, $y + 4.7, 'Your request', '#ffffff');
    $y += 7;
    foreach ([['Vehicle', (string)$app['vehicle']], ['Vehicle price', $R($price)], ['Commitment offered', $pct . '% — ' . $R($fee)]] as [$k, $v]) {
        $p->text(16, $y + 4.6, $k, 8.6, false, '#6b7385');
        $p->text(70, $y + 4.6, $v, 8.6, true);
        $p->line(12, $y + 6.8, 198, $y + 6.8, $line, 0.5);
        $y += 7;
    }
    $y += 6;

    $heading('Why we could not approve it');
    $why = array_values(array_filter(array_map(fn($k) => PA_DECLINE_REASONS[$k] ?? null, $reasons)));
    if (trim($note) !== '') $why[] = trim($note);
    if (!$why) $why[] = 'A pre-auction sale is only possible while the vehicle qualifies and the financial institution holding it accepts a sale before the auction. On this vehicle we are not able to go ahead this time.';
    $bullets(array_map(fn($t) => ['', $t], $why));

    $heading($invoiced ? 'Your commitment fee' : 'You owe us nothing');
    if ($invoiced) {
        $bullets([
            ['Refund.', 'If you have already paid the commitment fee, it is refunded within 3 to 5 business days to the same account it was paid from.'],
            ['Charges.', 'A deduction of ' . $R(PA_REFUND_FEE) . ' applies for administration and bank processing charges.'],
            ['Not reflecting?', 'If the refund does not reflect within 5 business days, contact us with your proof of payment.'],
        ]);
    } else {
        $para('No commitment fee has been invoiced or charged on this request, so there is nothing to pay.');
    }

    $heading('What you can do next');
    $bullets([
        ['Choose another vehicle.', 'Every coming-soon vehicle can be secured or financed before the auction, and so can live vehicles with no bids yet. Reply to this letter or call ' . COMPANY_PHONE . ' and we will suggest vehicles that suit your budget.'],
        ['Bid at auction.', 'Register as a bidder with a refundable R10,000 deposit and bid live on the floor or online. The full DEKRA report can be viewed at our office.'],
        ['Get a finance quote.', 'Terms from 6 to 72 months on qualifying vehicles: apply on our website.'],
    ]);
    $para('This decision is not a reflection of your creditworthiness or standing; it relates only to this vehicle. Thank you for your understanding. We value your interest and hope to help you find the right vehicle soon.');

    $need(22);
    $y += 2;
    $p->text(12, $y, 'Kind regards,', 8.6); $y += 5;
    $p->text(12, $y, 'Pre-Auction Department, ' . COMPANY_TRADING_AS, 8.6, true); $y += 8;

    // Company details
    $need(66);
    $heading('Verify our company details');
    $p->text(12, $y, 'Check these details against the CIPC and SARS records. Our banking details never change by email, SMS or WhatsApp.', 8.2, false, $muted);
    $y += 5;
    foreach ([
        ['Registered name', COMPANY_REGISTERED_NAME . ' (Pty) Ltd, trading as ' . COMPANY_TRADING_AS],
        ['Registration no.', COMPANY_REG_NO . ' (registered ' . COMPANY_REG_DATE . ')'],
        ['SARS tax no.', COMPANY_SARS_NO],
        ['Address', COMPANY_ADDRESS],
        ['Phone', COMPANY_PHONE . ' / ' . COMPANY_PHONE2],
        ['Email', COMPANY_EMAIL],
        ['Website', $site],
    ] as [$k, $v]) {
        $p->text(12, $y + 4, $k, 8.6, false, '#6b7385');
        $p->text(60, $y + 4, $v, 8.6, true);
        $p->line(12, $y + 6.2, 198, $y + 6.2, $line, 0.5);
        $y += 6.8;
    }
    return $p->output();
}

/** Saves the decline letter in the application's private folder; returns the path ('' on failure). */
function paDeclineLetterFile(array $app, array $reasons, string $note, bool $invoiced): string {
    try {
        $path = finUploadDir((int)$app['id']) . 'letter_of_decline_' . preg_replace('/[^A-Za-z0-9-]/', '', finRef($app)) . '.pdf';
        return @file_put_contents($path, paDeclineLetterPdf($app, $reasons, $note, $invoiced)) ? $path : '';
    } catch (Throwable $e) {
        error_log('[AHG] decline letter PDF: ' . $e->getMessage());
        return '';
    }
}

// ── Pre-auction: Commitment Declined letter (institution declined a paid commitment) ──

/** The Commitment Declined letter (PDF): apology, figures, and the two choices. Uses details['review']. */
function paCommitmentDeclinedPdf(array $app): string {
    $navy = '#0d2b7b'; $muted = '#7a8499'; $line = '#d9dfe9'; $ink = '#222222';
    $r     = $app['details']['review'];
    $full  = (int)$r['percent'] >= 100;
    $R     = fn($n) => 'R' . number_format((float)$n, 2, '.', ',');
    $first = ucfirst(explode(' ', trim((string)$app['name']))[0] ?: 'Client');
    $site  = defined('CANONICAL_URL') ? preg_replace('~^https?://(www\.)?~', '', CANONICAL_URL) : COMPANY_WEBSITE;

    $p = new AhgPdf();
    $p->setLogo(regLogoJpeg());
    $p->addPage();
    ahgPdfHeader($p, 'COMMITMENT DECLINED');

    $p->label(12, 49, 'To');
    $p->text(12, 56, (string)$app['name'], 12.5, true);
    $by = 62;
    foreach (array_filter([(string)($app['email'] ?? ''), (string)($app['phone'] ?? '')]) as $l) { $p->text(12, $by, $l, 8.6); $by += 5.3; }
    foreach ([['Reference', finRef($app)], ['Date', date('d M Y', strtotime((string)$r['at']))], ['Institution', (string)$r['institution']]] as $i => [$k, $v]) {
        $p->label(115, 49 + $i * 5.3, $k);
        $p->text(198, 49 + $i * 5.3, $v, 8.6, true, $ink, 'R');
    }
    $p->rect(115, 66, 83, 15, '#fff4e5', '#f0c98a', 3, 0.6);
    $p->label(156.5, 71, $full ? 'To settle in full' : 'Top-up to ' . (int)$r['percent'] . '%', '#8a5a00', 'C');
    $p->text(156.5, 78, $R($r['topup']), 15, true, $navy, 'C');

    $y = 90;
    $lh = 8.6 * 1.55 / 2.834646;
    $newPage = function () use ($p, &$y, $navy) { $p->addPage(); $p->rect(12, 7, 186, 2.2, $navy, null, 1); $y = 18; };
    $need = function (float $h) use (&$y, $newPage) { if ($y + $h > 284) $newPage(); };
    $para = function (string $t) use ($p, &$y, $need, $lh) {
        $lines = AhgPdf::wrap($t, 8.6, false, 186);
        $need(count($lines) * $lh);
        foreach ($lines as $l) { $p->text(12, $y, $l, 8.6); $y += $lh; }
        $y += 2.5;
    };
    $heading = function (string $t) use ($p, &$y, $need, $navy) { $need(14); $y += 2; $p->text(12, $y, mb_strtoupper($t), 11, true, $navy); $y += 6.5; };
    $bullets = function (array $items, bool $numbered = false) use ($p, &$y, $need, $lh, $navy) {
        foreach ($items as $i => [$lead, $t]) {
            $leadW = $lead === '' ? 0 : AhgPdf::width($lead . ' ', 8.6, true) / 2.834646;
            $lines = AhgPdf::wrap($t, 8.6, false, 178, 178 - $leadW);
            $need(count($lines) * $lh + 1.5);
            if ($numbered) { $p->rect(13, $y - 3.4, 4.2, 4.2, $navy, null, 2.1); $p->text(15.1, $y - 0.4, (string)($i + 1), 6.3, true, '#ffffff', 'C'); }
            else $p->rect(13.6, $y - 2.6, 2.4, 2.4, $navy, null, 1.2);
            if ($lead !== '') $p->text(20, $y, $lead, 8.6, true);
            foreach ($lines as $li => $l) { $p->text($li === 0 ? 20 + $leadW : 20, $y, $l, 8.6); $y += $lh; }
            $y += 1.5;
        }
        $y += 1.5;
    };

    $p->text(12, $y, 'Dear ' . $first . ',', 9.5, true); $y += 7;
    $p->text(12, $y, 'RE: PRE-AUCTION COMMITMENT — ' . mb_strtoupper((string)$app['vehicle']), 9.5, true, $navy); $y += 8;
    $para('Thank you for your commitment payment of ' . $R($r['paid']) . '. We have received it and it is safe and recorded in your name. '
        . 'We are sorry to let you know that we are unable to accept a ' . (int)$r['paid_percent'] . '% commitment on this vehicle: it is sold on behalf of '
        . $r['institution'] . ', which requires ' . ($full ? 'full settlement' : 'a commitment of at least ' . (int)$r['percent'] . '%')
        . ' before we can issue your Buyer Code. We apologise for the inconvenience.');

    // Figures
    $rows = [['Vehicle', (string)$app['vehicle']], ['Vehicle price', $R($r['price'])], ['Commitment received', $R($r['paid']) . ' (' . (int)$r['paid_percent'] . '%)'],
             [$full ? 'Required (full settlement)' : 'Required by ' . $r['institution'] . ' (' . (int)$r['percent'] . '%)', $R($r['required'])],
             ['Top-up to pay', $R($r['topup'])]];
    if (!$full) $rows[] = ['Balance after your test drive', $R($r['balance_after'])];
    $need(8 + count($rows) * 7);
    $p->rect(12, $y, 186, 7, $navy, null, 2.5);
    $p->label(16, $y + 4.7, 'Your figures', '#ffffff');
    $y += 7;
    foreach ($rows as [$k, $v]) {
        $p->text(16, $y + 4.6, $k, 8.6, false, '#6b7385');
        $p->text(85, $y + 4.6, $v, 8.6, true);
        $p->line(12, $y + 6.8, 198, $y + 6.8, $line, 0.5);
        $y += 7;
    }
    $y += 6;

    if (trim((string)$r['note']) !== '') { $heading('A note from our team'); $para((string)$r['note']); }

    $heading('Your two choices — you decide');
    $bullets([
        ['Go ahead:', 'pay the top-up of ' . $R($r['topup']) . ' using the top-up invoice we send you. It comes off the price; it is not an extra cost. Once it reflects, your Buyer Code is issued and we book your test drive and inspection at our office'
            . ($full ? '.' : '. The balance of ' . $R($r['balance_after']) . ' is only due once you sign off your final decision after the test drive.')],
        ['Do not go ahead:', 'your commitment fee is refunded to the account you paid from within 3 to 5 business days, less ' . $R(PA_REFUND_FEE) . ' for administration and bank charges.'],
    ], true);
    $para('Choose on your request page: ' . finLink($app, 'review'));

    $heading('Please note');
    $bullets([
        ['', 'The vehicle stays reserved in your name while you decide. Call us on ' . COMPANY_PHONE . ' if you have any questions.'],
        ['', 'Pay only into the account on your official invoice. Our banking details never change by email, SMS or WhatsApp.'],
    ]);
    $para('We apologise for the inconvenience, and thank you for your understanding.');
    $need(14);
    $p->text(12, $y, 'Kind regards,', 8.6); $y += 5;
    $p->text(12, $y, 'Pre-Auction Department, ' . COMPANY_TRADING_AS, 8.6, true); $y += 8;

    $need(60);
    $heading('Verify our company details');
    $p->text(12, $y, 'Check these details against the CIPC and SARS records.', 8.2, false, $muted);
    $y += 5;
    foreach ([
        ['Registered name', COMPANY_REGISTERED_NAME . ' (Pty) Ltd, trading as ' . COMPANY_TRADING_AS],
        ['Registration no.', COMPANY_REG_NO . ' (registered ' . COMPANY_REG_DATE . ')'],
        ['SARS tax no.', COMPANY_SARS_NO],
        ['Address', COMPANY_ADDRESS],
        ['Phone', COMPANY_PHONE . ' / ' . COMPANY_PHONE2],
        ['Email', COMPANY_EMAIL],
        ['Website', $site],
    ] as [$k, $v]) {
        $p->text(12, $y + 4, $k, 8.6, false, '#6b7385');
        $p->text(60, $y + 4, $v, 8.6, true);
        $p->line(12, $y + 6.2, 198, $y + 6.2, $line, 0.5);
        $y += 6.8;
    }
    return $p->output();
}

function paCommitmentDeclinedFile(array $app): string {
    try {
        $path = finUploadDir((int)$app['id']) . 'commitment_declined_' . preg_replace('/[^A-Za-z0-9-]/', '', finRef($app)) . '.pdf';
        return @file_put_contents($path, paCommitmentDeclinedPdf($app)) ? $path : '';
    } catch (Throwable $e) {
        error_log('[AHG] commitment declined PDF: ' . $e->getMessage());
        return '';
    }
}
