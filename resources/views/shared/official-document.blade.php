<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $reference }}</title>
    <style>
        @page { size: A4 portrait; margin: 20mm; }
        @font-face {
            font-family: "MaprCarlito";
            src: url("{{ $font_regular }}") format("truetype");
            font-weight: 400;
        }
        @font-face {
            font-family: "MaprCarlito";
            src: url("{{ $font_bold }}") format("truetype");
            font-weight: 700;
        }
        * { box-sizing: border-box; }
        body { margin: 0; color: #000; background: #fff; font-family: MaprCarlito, Arial, sans-serif; font-size: 12pt; line-height: 1.35; }
        .logos { text-align: center; margin: 0 0 8px; }
        .logos img { height: 18mm; width: auto; margin: 0 3mm; vertical-align: middle; }
        .center { text-align: center; margin: 0; }
        h1 { font-size: 14pt; margin: 8px 0 2px; }
        h2 { font-size: 12pt; margin: 12px 0 6px; }
        .notice { margin: 6px 0 10px; font-size: 9pt; text-align: center; }
        table { width: 100%; border-collapse: collapse; background: #fff; margin: 0 0 10px; }
        thead { display: table-header-group; }
        th, td { border: 1px solid #000; background: #fff; color: #000; padding: 4px 6px; vertical-align: top; text-align: left; font-weight: 400; }
        th { width: 42%; }
        .impact th, .impact td { width: auto; text-align: center; }
        .signatures { page-break-inside: avoid; margin-top: 14px; }
        .signature-grid { width: 100%; }
        .signature-grid td { width: 50%; border: 0; padding: 8px 10px 0 0; }
        .signature-line { height: 16mm; border-bottom: 1px solid #000; margin: 8px 0 4px; }
        .signature-line img { height: 14mm; width: auto; }
        .muted { color: #000; font-size: 10pt; }
        .footer { position: fixed; left: 0; right: 0; bottom: -12mm; text-align: center; font-size: 9pt; }
    </style>
</head>
<body>
    <div class="footer">MapR | Building Damage Assessment and Resident Impact Report</div>
    <div class="logos">
        @foreach ($logos as $logo)
            <img src="{{ $logo['path'] }}" alt="{{ $logo['alt'] }}">
        @endforeach
    </div>
    <p class="center">Republic of the Philippines</p>
    <p class="center">City of Tandag | Province of Surigao del Sur</p>
    <p class="center"><strong>{{ $office_line }}</strong></p>
    <h1 class="center">{{ $title }}</h1>
    <p class="notice">Generated report from the saved MapR record. This is not the original imported attachment.</p>
    <h2>MapR Assessment Record</h2>
    <p>Report reference: {{ $reference }}</p>
    <p>Date prepared: {{ $prepared_at }}</p>
    <h2>Incident and building record</h2>
    <table>
        <thead>
            <tr><th>Field</th><th>Recorded details</th></tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr><th>{{ $row['label'] }}</th><td>{{ $row['value'] }}</td></tr>
            @endforeach
        </tbody>
    </table>
    <h2>Potential resident impact</h2>
    <table class="impact">
        <thead>
            <tr>
                <th>Linked buildings</th>
                <th>Households</th>
                <th>Residents</th>
                <th>Validation</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $impact['buildings'] }}</td>
                <td>{{ $impact['households'] }}</td>
                <td>{{ $impact['residents'] }}</td>
                <td>{{ $impact['validation'] }}</td>
            </tr>
        </tbody>
    </table>
    <section class="signatures">
        <h2>Signatories</h2>
        <table class="signature-grid">
            <tr>
                @foreach ($signatures as $signature)
                    <td>
                        <strong>{{ $signature['title'] }}</strong>
                        <div class="signature-line">
                            @if ($signature['image'])
                                <img src="{{ $signature['image'] }}" alt="Executed signature">
                            @endif
                        </div>
                        <div>{{ $signature['name'] }}</div>
                        <div class="muted">{{ $signature['position'] }}</div>
                        <div class="muted">{{ $signature['caption'] }}</div>
                    </td>
                @endforeach
            </tr>
        </table>
    </section>
</body>
</html>
