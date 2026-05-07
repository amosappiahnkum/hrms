<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>{{ config('app.name') }} — Notification</title>
    <!--[if mso]>
    <noscript>
        <xml>
            <o:OfficeDocumentSettings>
                <o:PixelsPerInch>96</o:PixelsPerInch>
            </o:OfficeDocumentSettings>
        </xml>
    </noscript>
    <![endif]-->
    <style>
        /* Reset */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; border-collapse: collapse; }
        img { -ms-interpolation-mode: bicubic; border: 0; outline: none; text-decoration: none; }

        /* Base */
        body {
            background-color: #f0f2f5;
            font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, 'Helvetica Neue', Arial, sans-serif;
            color: #1a1a2e;
            line-height: 1.6;
            width: 100% !important;
            min-width: 100%;
            -webkit-font-smoothing: antialiased;
        }

        /* Wrapper */
        .email-wrapper {
            width: 100%;
            background-color: #f0f2f5;
            padding: 48px 16px;
        }

        /* Container */
        .email-container {
            max-width: 600px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 24px rgba(0, 0, 0, 0.07);
        }

        /* Header */
        .email-header {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            padding: 40px 48px 36px;
            text-align: left;
        }

        .email-header .brand {
            display: inline-block;
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: #e2e8f0;
            opacity: 0.85;
            text-decoration: none;
        }

        .email-header .accent-bar {
            width: 40px;
            height: 3px;
            background: linear-gradient(90deg, #4f8ef7, #7c3aed);
            border-radius: 2px;
            margin-top: 14px;
        }

        /* Body */
        .email-body {
            padding: 48px 48px 40px;
        }

        .email-body .label {
            display: inline-block;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: #4f8ef7;
            margin-bottom: 12px;
        }

        .email-body h1 {
            font-size: 26px;
            font-weight: 700;
            color: #1a1a2e;
            line-height: 1.3;
            margin-bottom: 20px;
            letter-spacing: -0.02em;
        }

        .email-body .divider {
            width: 100%;
            height: 1px;
            background-color: #eef0f4;
            margin: 24px 0;
        }

        .email-body p {
            font-size: 15.5px;
            color: #4a5568;
            line-height: 1.75;
            margin-bottom: 0;
        }

        /* CTA */
        .cta-wrapper {
            padding: 0 48px 48px;
        }

        .cta-button {
            display: inline-block;
            background: linear-gradient(135deg, #4f8ef7 0%, #3b6fd4 100%);
            color: #ffffff !important;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            letter-spacing: 0.03em;
            padding: 14px 32px;
            border-radius: 8px;
            box-shadow: 0 4px 14px rgba(79, 142, 247, 0.35);
            transition: all 0.2s ease;
        }

        /* Footer */
        .email-footer {
            background-color: #f8fafc;
            border-top: 1px solid #eef0f4;
            padding: 28px 48px 32px;
        }

        .email-footer .sign-off {
            font-size: 14px;
            color: #64748b;
            margin-bottom: 20px;
        }

        .email-footer .sign-off strong {
            display: block;
            font-size: 14px;
            font-weight: 700;
            color: #1a1a2e;
            margin-top: 4px;
        }

        .email-footer .meta {
            font-size: 12px;
            color: #94a3b8;
            line-height: 1.7;
        }

        .email-footer .meta a {
            color: #4f8ef7;
            text-decoration: none;
        }

        /* Responsive */
        @media only screen and (max-width: 600px) {
            .email-wrapper { padding: 24px 12px; }
            .email-header { padding: 28px 28px 24px; }
            .email-body { padding: 32px 28px 28px; }
            .cta-wrapper { padding: 0 28px 32px; }
            .email-footer { padding: 24px 28px 28px; }
            .email-body h1 { font-size: 22px; }
        }
    </style>
</head>
<body>

<div class="email-wrapper">
    <div class="email-container">

        {{-- Header --}}
        <div class="email-header">
            <a href="{{ config('app.url') }}" class="brand">{{ config('app.name') }}</a>
            <div class="accent-bar"></div>
        </div>

        {{-- Body --}}
        <div class="email-body">
            <span class="label">Notification</span>
            <h1>You have a new update</h1>
            <div class="divider"></div>
            <p>{{ $body }}</p>
        </div>

        {{-- CTA --}}
        <div class="cta-wrapper">
            <a href="{{ config('app.url') }}" class="cta-button">View Details &rarr;</a>
        </div>

        {{-- Footer --}}
        <div class="email-footer">
            <div class="sign-off">
                Thanks &amp; regards,
                <strong>The {{ config('app.name') }} Team</strong>
            </div>
            <p class="meta">
                You're receiving this email because an action occurred on your account.<br>
                If you did not expect this, please <a href="{{ config('app.url') }}">contact support</a>.<br><br>
                &copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.
            </p>
        </div>

    </div>
</div>

</body>
</html>
