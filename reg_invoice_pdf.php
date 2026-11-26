<?php
/**
 * Registration invoice PDF, drawn in the same layout as the Auction Hub invoice
 * (invoice_view.php) used for finance and pre-auction invoices. No PDF library:
 * a small A4 canvas writes the PDF directly. Positions are in mm from the top left.
 */

final class AhgPdf {
    const W = 595.28;
    const H = 841.89;
    private array $pages = [];
    private ?array $logo = null;  // [jpeg bytes, px width, px height]

    private const WR = '278 278 355 556 556 889 667 191 333 333 389 584 278 333 278 278 556 556 556 556 556 556 556 556 556 556 278 278 584 584 584 556 1015 667 667 722 722 667 611 778 722 278 500 667 556 833 722 778 667 778 722 667 611 722 667 944 667 667 611 278 278 278 469 556 333 556 556 500 556 556 278 556 556 222 222 500 222 833 556 556 556 556 333 500 278 556 500 722 500 500 500 334 260 334 584';
    private const WB = '278 333 474 556 556 889 722 238 333 333 389 584 278 333 278 278 556 556 556 556 556 556 556 556 556 556 333 333 584 584 584 611 975 722 722 722 722 667 611 778 722 278 556 722 611 833 722 778 667 778 722 667 611 722 667 944 667 667 611 333 278 333 584 556 333 556 611 556 611 556 333 611 611 278 278 556 278 889 611 611 611 611 389 556 333 611 556 778 556 556 500 389 280 389 584';

    public static function mm(float $v): float { return $v * 2.834646; }

    public function addPage(): void { $this->pages[] = ''; }
    public function setLogo(?array $jpeg): void { $this->logo = $jpeg; }
    public function hasLogo(): bool { return $this->logo !== null; }

    private function out(string $s): void { $this->pages[count($this->pages) - 1] .= $s . "\n"; }

    private static function rgb(string $hex): string {
        $h = ltrim($hex, '#');
        return sprintf('%.3F %.3F %.3F', hexdec(substr($h, 0, 2)) / 255, hexdec(substr($h, 2, 2)) / 255, hexdec(substr($h, 4, 2)) / 255);
    }

    public static function enc(string $s): string {
        $t = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', $s);
        return $t === false ? preg_replace('/[^\x20-\x7E]/', '?', $s) : $t;
    }

    /** Text width in pt. */
    public static function width(string $s, float $size, bool $bold = false, float $spacing = 0): float {
        static $w = [];
        $key = $bold ? 'b' : 'r';
        $w[$key] ??= array_map('intval', explode(' ', $bold ? self::WB : self::WR));
        $enc = self::enc($s);
        $total = 0;
        for ($i = 0, $n = strlen($enc); $i < $n; $i++) {
            $c = ord($enc[$i]);
            $total += ($c >= 32 && $c <= 126) ? $w[$key][$c - 32] : 556;
        }
        return $total * $size / 1000 + $spacing * max(0, strlen($enc) - 1);
    }

    /** Word-wraps to a width in mm. $firstWidth lets the first line be shorter (inline bold lead). */
    public static function wrap(string $s, float $size, bool $bold, float $widthMm, ?float $firstWidthMm = null): array {
        $lines = [];
        $line = '';
        $max = self::mm($firstWidthMm ?? $widthMm);
        foreach (preg_split('/\s+/', trim($s)) as $word) {
            $try = $line === '' ? $word : $line . ' ' . $word;
            if ($line !== '' && self::width($try, $size, $bold) > $max) {
                $lines[] = $line;
                $line = $word;
                $max = self::mm($widthMm);
            } else {
                $line = $try;
            }
        }
        if ($line !== '') $lines[] = $line;
        return $lines;
    }

    public function rect(float $x, float $y, float $w, float $h, ?string $fill, ?string $stroke = null, float $r = 0, float $lw = 0.75): void {
        $X = self::mm($x); $Y = self::H - self::mm($y + $h); $W = self::mm($w); $Hh = self::mm($h);
        $R = min(self::mm($r), $W / 2, $Hh / 2);
        $s = 'q ';
        if ($fill)   $s .= self::rgb($fill) . ' rg ';
        if ($stroke) $s .= self::rgb($stroke) . ' RG ' . $lw . ' w ';
        if ($R <= 0) {
            $s .= sprintf('%.2F %.2F %.2F %.2F re ', $X, $Y, $W, $Hh);
        } else {
            $k = 0.5523 * $R;
            $s .= sprintf('%.2F %.2F m %.2F %.2F l ', $X + $R, $Y, $X + $W - $R, $Y)
                . sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c ', $X + $W - $R + $k, $Y, $X + $W, $Y + $R - $k, $X + $W, $Y + $R)
                . sprintf('%.2F %.2F l ', $X + $W, $Y + $Hh - $R)
                . sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c ', $X + $W, $Y + $Hh - $R + $k, $X + $W - $R + $k, $Y + $Hh, $X + $W - $R, $Y + $Hh)
                . sprintf('%.2F %.2F l ', $X + $R, $Y + $Hh)
                . sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c ', $X + $R - $k, $Y + $Hh, $X, $Y + $Hh - $R + $k, $X, $Y + $Hh - $R)
                . sprintf('%.2F %.2F l ', $X, $Y + $R)
                . sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c h ', $X, $Y + $R - $k, $X + $R - $k, $Y, $X + $R, $Y);
        }
        $s .= ($fill && $stroke) ? 'B' : ($fill ? 'f' : 'S');
        $this->out($s . ' Q');
    }

    public function line(float $x1, float $y1, float $x2, float $y2, string $color, float $lw = 0.75): void {
        $this->out(sprintf('q %s RG %.2F w %.2F %.2F m %.2F %.2F l S Q', self::rgb($color), $lw,
            self::mm($x1), self::H - self::mm($y1), self::mm($x2), self::H - self::mm($y2)));
    }

    /** $y is the text baseline. $spacing is letter spacing in pt. */
    public function text(float $x, float $y, string $str, float $size, bool $bold = false, string $color = '#222222', string $align = 'L', float $spacing = 0): void {
        $X = self::mm($x);
        $w = self::width($str, $size, $bold, $spacing);
        if ($align === 'R') $X -= $w; elseif ($align === 'C') $X -= $w / 2;
        $esc = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], self::enc($str));
        $this->out(sprintf('BT %s rg /%s %.2F Tf %.2F Tc %.2F %.2F Td (%s) Tj ET', self::rgb($color), $bold ? 'F2' : 'F1', $size, $spacing, $X, self::H - self::mm($y), $esc));
    }

    /** Small caps-style label, as .lbl in invoice_view.php. */
    public function label(float $x, float $y, string $str, string $color = '#7a8499', string $align = 'L'): void {
        $this->text($x, $y, mb_strtoupper($str), 6.6, true, $color, $align, 6.6 * 0.32);
    }

    public function logo(float $x, float $y, float $size): void {
        if ($this->logo) $this->out(sprintf('q %.2F 0 0 %.2F %.2F %.2F cm /ImLogo Do Q', self::mm($size), self::mm($size), self::mm($x), self::H - self::mm($y + $size)));
    }

    public function output(): string {
        $objs = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
        ];
        $xobj = '';
        if ($this->logo) {
            [$jpg, $pw, $ph] = $this->logo;
            $objs[5] = '<< /Type /XObject /Subtype /Image /Width ' . $pw . ' /Height ' . $ph . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen($jpg) . " >>\nstream\n" . $jpg . "\nendstream";
            $xobj = ' /XObject << /ImLogo 5 0 R >>';
        }
        $next = 6;
        $kids = [];
        foreach ($this->pages as $content) {
            $cid = $next++;
            $pid = $next++;
            $objs[$cid] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . 'endstream';
            $objs[$pid] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::W . ' ' . self::H . '] /Resources << /Font << /F1 3 0 R /F2 4 0 R >>' . $xobj . ' >> /Contents ' . $cid . ' 0 R >>';
            $kids[] = $pid . ' 0 R';
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($objs);

        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objs as $id => $body) {
            $offsets[$id] = strlen($out);
            $out .= $id . " 0 obj\n" . $body . "\nendobj\n";
        }
        $size = max(array_keys($objs)) + 1;
        $xref = strlen($out);
        $out .= "xref\n0 " . $size . "\n0000000000 65535 f \n";
        for ($i = 1; $i < $size; $i++) {
            $out .= isset($offsets[$i]) ? sprintf("%010d 00000 n \n", $offsets[$i]) : "0000000000 65535 f \n";
        }
        return $out . "trailer\n<< /Size " . $size . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";
    }
}

/** The logo as a JPEG on white (PDF cannot embed the PNG directly without zlib work). Null when GD is missing. */
function regLogoJpeg(): ?array {
    $path = __DIR__ . '/logo-round.png';
    if (!function_exists('imagecreatefrompng') || !is_file($path)) return null;
    $src = @imagecreatefrompng($path);
    if (!$src) return null;
    $px = 260;
    $dst = imagecreatetruecolor($px, $px);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagealphablending($dst, true);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $px, $px, imagesx($src), imagesy($src));
    ob_start();
    imagejpeg($dst, null, 88);
    $jpg = (string)ob_get_clean();
    imagedestroy($src);
    imagedestroy($dst);
    return $jpg === '' ? null : [$jpg, $px, $px];
}

/** Bidding steps: [short heading, text]. Shared with the registration email. */
function regBiddingSteps(): array {
    return [
        ['Pay deposit',     'Pay the registration deposit using your reference, then upload your proof of payment in your dashboard.'],
        ['Get approved',    'Once we approve your registration you receive a bidder number. Your bids show your name and that number.'],
        ['Place bids',      'Open a live auction and place your bid on a vehicle before its timer closes.'],
        ['Outbid alerts',   'If someone bids higher, we email you so you can bid again while the auction is still open.'],
        ['Win the vehicle', 'If you win, we send you an invoice. Pay it, then arrange collection of the vehicle.'],
    ];
}

/** Registration stage only: what happens once the deposit is paid. */
function regAfterPaySteps(): array {
    return [
        ['Pay and upload POP', 'Pay the deposit by EFT using your payment reference, then upload your proof of payment in your dashboard.'],
        ['We verify',          'We check that your payment has cleared, usually within 1-2 business days.'],
        ['Bidder number',      'We activate your bidder number and email you. You can then bid in our live auctions.'],
    ];
}

/** Steps after a win: [short heading, text]. Shared with the registration email and the guide. */
function regAfterWinSteps(): array {
    return [
        ['Win confirmed',   'Receive confirmation that you have won the bid.'],
        ['Invoice emailed', 'Receive your sales order / invoice by email.'],
        ['Check charges',   'Check the vehicle price and any additional charges.'],
        ['Pay by EFT',      'Make full payment by EFT within one business day of receiving the invoice.'],
        ['Send POP',        'Send us proof of payment.'],
        ['Clearance',       'Wait for payment clearance and verification.'],
        ['Release Note',    'Receive your vehicle Release Note.'],
        ['Collect in 72h',  'Arrange vehicle collection within 72 hours of the Release Note.'],
        ['ID & sign',       'Present your ID and sign the delivery note.'],
        ['Transfer',        'Complete the ownership-transfer process and receive your registration documents.'],
    ];
}

/** Draws a grid of numbered step boxes (as .steps in invoice_view.php). Returns the bottom y. */
function regDrawSteps(AhgPdf $p, float $top, array $steps, int $cols, int $start = 1): float {
    $gap = 2.4;
    $w = (186 - $gap * ($cols - 1)) / $cols;
    $lh = 7 * 1.4 / 2.834646;   // 7pt text, line-height 1.4, in mm
    $y = $top;
    foreach (array_chunk($steps, $cols) as $ri => $row) {
        $wrapped = array_map(fn($s) => AhgPdf::wrap($s[1], 7, false, $w - 4.6), $row);
        $h = 9.2 + max(array_map('count', $wrapped)) * $lh + 1.6;
        foreach ($row as $ci => [$head, $text]) {
            $x = 12 + $ci * ($w + $gap);
            $n = $start + $ri * $cols + $ci;
            $p->rect($x, $y, $w, $h, '#f7f9fc', '#d9dfe9', 2.4, 0.6);
            $p->rect($x + 2.3, $y + 2.3, 4.4, 4.4, '#0d2b7b', null, 2.2);
            $p->text($x + 4.5, $y + 5.4, (string)$n, 6.5, true, '#ffffff', 'C');
            $p->text($x + 8.3, $y + 5.6, $head, 7.5, true, '#0d2b7b');
            foreach ($wrapped[$ci] as $li => $l) $p->text($x + 2.3, $y + 10.4 + $li * $lh, $l, 7, false, '#6b7385');
        }
        $y += $h + $gap;
    }
    return $y - $gap;
}

/** The registration invoice PDF for one bidder. */
function regInvoicePdf(array $user, string $ref, array $bank): string {
    $navy = '#0d2b7b'; $muted = '#7a8499'; $line = '#d9dfe9'; $ink = '#222222';
    $amount = (string)$bank['reg_fee_label'];
    $site   = defined('CANONICAL_URL') ? preg_replace('~^https?://(www\.)?~', '', CANONICAL_URL) : COMPANY_WEBSITE;
    $invNo  = 'REG-' . (int)($user['id'] ?? 0);

    $p = new AhgPdf();
    $p->setLogo(regLogoJpeg());
    $p->addPage();

    // Header
    $p->rect(12, 7, 186, 2.2, $navy, null, 1);
    $tx = 12;
    if ($p->hasLogo()) { $p->logo(12, 15, 24); $tx = 41; }
    $p->text($tx, 22, mb_strtoupper(COMPANY_TRADING_AS), 20, true, $navy);
    $p->text($tx, 27.5, COMPANY_ADDRESS, 7.6);
    $p->text($tx, 31.5, str_replace(' ', '', COMPANY_PHONE) . '  |  ' . COMPANY_EMAIL . '  |  ' . $site, 7.6);
    $p->text($tx, 35.5, 'Reg: ' . COMPANY_REG_NO . '  |  SARS Tax No: ' . COMPANY_SARS_NO . '  |  Reg Date: ' . COMPANY_REG_DATE, 7.6);
    $p->text(198, 20, 'REGISTRATION INVOICE', 12.5, true, $navy, 'R');
    $p->line(12, 43, 198, 43, $line);

    // Bill to / invoice info
    $p->label(12, 49, 'Bill to');
    $p->text(12, 56, (string)$user['full_name'], 12.5, true);
    $by = 62;
    if (!empty($user['email'])) { $p->text(12, $by, 'Email: ' . $user['email'], 8.6); $by += 5.3; }
    if (!empty($user['phone'])) { $p->text(12, $by, 'Tel: ' . $user['phone'], 8.6); }
    $info = [['Invoice no.', $invNo], ['Invoice date', date('d M Y')], ['Payment ref.', $ref], ['Due date', 'On receipt']];
    foreach ($info as $i => [$k, $v]) {
        $p->label(115, 49 + $i * 5.3, $k);
        $p->text(198, 49 + $i * 5.3, $v, 8.6, true, $ink, 'R');
    }
    $p->rect(115, 68, 83, 15, '#eef2f8', $line, 3, 0.6);
    $p->label(156.5, 73, 'Balance due', $muted, 'C');
    $p->text(156.5, 80, $amount, 15, true, $navy, 'C');

    // Description bar and item
    $p->rect(12, 87, 186, 8, $navy, null, 3);
    $p->label(17, 92.2, 'Description', '#ffffff');
    $p->label(193, 92.2, 'Total (ZAR)', '#ffffff', 'R');
    $p->text(17, 101.5, 'Bidder registration deposit (refundable)', 11.3, true);
    $p->text(193, 101.5, $amount, 9.8, true, $ink, 'R');
    $spec = 'Registers you to bid in ' . COMPANY_TRADING_AS . ' live auctions. Your bidder number is issued once your payment is verified. Refunds are processed to the original paying account.';
    foreach (AhgPdf::wrap($spec, 7.6, false, 176) as $i => $l) $p->text(17, 106.5 + $i * 4.1, $l, 7.6, false, $muted);

    // Note + signature (left), totals (right)
    $p->label(12, 121, 'Note');
    $note = 'This deposit is refundable. If you win, it is deducted from the purchase price. If you do not buy, it is refunded to the account you paid from, less R' . number_format(102, 2) . ' for admin and bank charges.';
    foreach (AhgPdf::wrap($note, 8.3, false, 86) as $i => $l) $p->text(12, 126 + $i * 4.5, $l, 8.3);
    $p->line(12, 148, 84, 148, $ink, 0.6);
    $p->label(12, 151.5, 'Authorised signature');
    $p->label(12, 155.3, COMPANY_TRADING_AS);

    $p->text(108, 123, 'Registration deposit', 8.6, false, '#6b7385');
    $p->text(198, 123, $amount, 8.6, true, $ink, 'R');
    $p->line(108, 126, 198, 126, $line, 0.6);
    $p->text(108, 130.5, 'Total', 8.6, true);
    $p->text(198, 130.5, $amount, 8.6, true, $ink, 'R');
    $p->line(108, 137, 198, 137, $navy, 1.6);
    $p->text(108, 143.5, 'BALANCE DUE', 8.6, true, $navy);
    $p->text(198, 144, $amount, 15, true, $navy, 'R');

    // Steps
    $p->label(12, 163, 'What happens after you pay');
    $y = regDrawSteps($p, 165.5, regAfterPaySteps(), 3);

    // Payment box + instructions: on page 1 when it fits, otherwise at the top of page 2
    $drawPay = function (float $top) use ($p, $amount, $ref, $line, $muted) {
        $p->rect(12, $top, 100, 27, '#f3f6fa', $line, 3, 0.6);
        $p->label(17, $top + 5.5, 'Payment details');
        foreach ([['Amount', $amount], ['Reference', $ref], ['Bank details', 'Sent after your dashboard request is approved']] as $i => [$k, $v]) {
            $p->text(17, $top + 11 + $i * 4.8, $k, 8, false, '#6b7385');
            $p->text(46, $top + 11 + $i * 4.8, $v, 8, true);
        }
        $p->text(119, $top + 4, 'Payment instructions.', 7.8, true);
        $instr = 'Pay by EFT using the reference shown and upload your proof of payment in your dashboard, or email it to ' . COMPANY_EMAIL . '. Request our bank account details in your dashboard; we send them once the request is approved. All amounts are in South African Rand.';
        foreach (AhgPdf::wrap($instr, 7.8, false, 79) as $i => $l) $p->text(119, $top + 8.2 + $i * 4.3, $l, 7.8);
        return $top + 27;
    };
    $payOnFirst = ($y + 4 + 27) <= 288;
    if ($payOnFirst) $drawPay($y + 4);

    // Page 2: company details and terms
    $p->addPage();
    $p->rect(12, 7, 186, 2.2, $navy, null, 1);
    $y = 16;
    if (!$payOnFirst) $y = $drawPay($y) + 8;
    $p->text(12, $y + 6, 'OUR COMPANY DETAILS', 12, true, $navy);
    $p->text(12, $y + 12, 'Check these details against the CIPC and SARS records before you pay us.', 8.2, false, $muted);
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
    $y += 18;
    foreach ($rows as [$k, $v]) {
        $p->text(12, $y + 4, $k, 8.6, false, '#6b7385');
        $p->text(60, $y + 4, $v, 8.6, true);
        $p->line(12, $y + 6.2, 198, $y + 6.2, $line, 0.5);
        $y += 6.8;
    }

    $y += 8;
    $p->text(12, $y, 'TERMS & CONDITIONS', 12, true, $navy);
    $y += 8;
    $terms = [
        ['1. General.', 'This invoice is issued by ' . COMPANY_REGISTERED_NAME . ' (Pty) Ltd, trading as ' . COMPANY_TRADING_AS . '. All amounts are in South African Rand (ZAR).'],
        ['2. Payment.', 'Pay by electronic funds transfer (EFT) using the payment reference on this invoice, and upload your proof of payment in your dashboard or email it to ' . COMPANY_EMAIL . '.'],
        ['3. Registration.', 'Your bidder number is issued once your payment is received and verified. You can bid once your registration is approved.'],
        ['4. Refunds.', 'The deposit is deducted from the purchase price if you win. If you do not buy, it is refunded to the account you paid from, less R102.00 for admin and bank charges.'],
        ['5. Governing Law.', 'This agreement and any dispute arising from it are governed by and construed in accordance with the laws of the Republic of South Africa.'],
    ];
    $lh = 8.2 * 1.6 / 2.834646;
    foreach ($terms as [$lead, $text]) {
        $leadW = AhgPdf::width($lead . ' ', 8.2, true) / 2.834646;
        $p->text(12, $y, $lead, 8.2, true);
        foreach (AhgPdf::wrap($text, 8.2, false, 186, 186 - $leadW) as $i => $l) {
            $p->text($i === 0 ? 12 + $leadW : 12, $y + $i * $lh, $l, 8.2);
        }
        $y += count(AhgPdf::wrap($text, 8.2, false, 186, 186 - $leadW)) * $lh + 3;
    }
    $y += 8;
    $p->text(105, $y, 'Thank you for registering.', 8, false, $muted, 'C');
    $p->text(105, $y + 4.2, COMPANY_TRADING_AS . ' · ' . $site, 8, false, $muted, 'C');

    return $p->output();
}
