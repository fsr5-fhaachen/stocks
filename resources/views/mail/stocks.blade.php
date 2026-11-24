{{-- Theme of the mails (App\Support\Notifications\MailLayout): the tokens of the app in light
     mode, the button in the accent colour of the instance. Mails are always light, many mail
     programs have no dark mode. --}}
@php
    $accent = (string) app(\App\Support\Settings::class)->get('organization.accent_color') ?: '#22948c';
@endphp
body,
body *:not(html):not(style):not(br):not(tr):not(code) {
    box-sizing: border-box;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
    position: relative;
}

body {
    -webkit-text-size-adjust: none;
    background-color: #f3f4f6;
    color: #111827;
    height: 100%;
    line-height: 1.5;
    margin: 0;
    padding: 0;
    width: 100% !important;
}

p, ul, ol, blockquote {
    line-height: 1.5;
    text-align: start;
}

a {
    color: {{ $accent }};
}

a img {
    border: none;
}

h1 {
    color: #111827;
    font-size: 20px;
    font-weight: bold;
    margin-top: 0;
    text-align: start;
}

h2, h3 {
    color: #111827;
    font-size: 16px;
    font-weight: bold;
    margin-top: 0;
    text-align: start;
}

p {
    font-size: 15px;
    margin-top: 0;
    text-align: start;
}

p.sub {
    font-size: 12px;
}

img {
    max-width: 100%;
}

.wrapper, .body {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 100%;
    background-color: #f3f4f6;
    margin: 0;
    padding: 0;
    width: 100%;
}

.content {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 100%;
    margin: 0;
    padding: 0;
    width: 100%;
}

.header {
    padding: 28px 0 20px;
    text-align: center;
}

.header a {
    color: #111827;
    font-size: 19px;
    font-weight: bold;
    text-decoration: none;
}

.logo {
    max-height: 64px;
    max-width: 200px;
}

/* card like UiCard: white, line, rounded */
.inner-body {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 570px;
    background-color: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    margin: 0 auto;
    padding: 0;
    width: 570px;
}

.inner-body a {
    word-break: break-all;
}

.content-cell {
    max-width: 100vw;
    padding: 32px;
}

.subcopy {
    border-top: 1px solid #e5e7eb;
    margin-top: 24px;
    padding-top: 24px;
}

.subcopy p {
    color: #6b7280;
    font-size: 13px;
}

.footer {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 570px;
    margin: 0 auto;
    padding: 0;
    text-align: center;
    width: 570px;
}

.footer p {
    color: #6b7280;
    font-size: 12px;
    text-align: center;
}

.footer a {
    color: #6b7280;
    text-decoration: underline;
}

.table table {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 100%;
    margin: 24px auto;
    width: 100%;
}

.table th {
    border-bottom: 1px solid #e5e7eb;
    margin: 0;
    padding-bottom: 8px;
}

.table td {
    color: #111827;
    font-size: 15px;
    line-height: 18px;
    margin: 0;
    padding: 10px 0;
}

.action {
    -premailer-cellpadding: 0;
    -premailer-cellspacing: 0;
    -premailer-width: 100%;
    margin: 28px auto;
    padding: 0;
    text-align: center;
    width: 100%;
    float: unset;
}

/* like UiButton primary */
.button {
    -webkit-text-size-adjust: none;
    border-radius: 6px;
    color: #ffffff;
    display: inline-block;
    font-weight: 600;
    overflow: hidden;
    text-decoration: none;
}

.button-blue,
.button-primary {
    background-color: {{ $accent }};
    border-bottom: 10px solid {{ $accent }};
    border-left: 20px solid {{ $accent }};
    border-right: 20px solid {{ $accent }};
    border-top: 10px solid {{ $accent }};
}

.button-green,
.button-success {
    background-color: #16a34a;
    border-bottom: 10px solid #16a34a;
    border-left: 20px solid #16a34a;
    border-right: 20px solid #16a34a;
    border-top: 10px solid #16a34a;
}

.button-red,
.button-error {
    background-color: #dc2626;
    border-bottom: 10px solid #dc2626;
    border-left: 20px solid #dc2626;
    border-right: 20px solid #dc2626;
    border-top: 10px solid #dc2626;
}

.panel {
    border-left: {{ $accent }} solid 4px;
    margin: 21px 0;
}

.panel-content {
    background-color: #f9fafb;
    color: #111827;
    padding: 16px;
}

.panel-item {
    padding: 0;
}

.break-all {
    word-break: break-all;
}
