<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $reference }}</title>
    <style>
        @page { size: A4 portrait; margin: 18mm 18mm 22mm; }
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
        body { margin: 0; color: #000; background: #fff; font-family: MaprCarlito, Arial, sans-serif; font-size: 11pt; line-height: 1.35; }
        .logos { text-align: center; margin: 0 0 6px; }
        .logos img { height: 16mm; width: auto; margin: 0 2.5mm; vertical-align: middle; }
        .center { text-align: center; margin: 0; }
        h1 { font-size: 14pt; margin: 8px 0 4px; }
        h2 { font-size: 12pt; margin: 14px 0 6px; page-break-after: avoid; }
        .draft { margin: 0 0 8px; text-align: center; font-weight: 700; letter-spacing: 0.12em; }
        table { width: 100%; border-collapse: collapse; margin: 0 0 8px; }
        table.record { page-break-inside: avoid; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        th, td { border: 1px solid #000; padding: 4px 6px; vertical-align: top; text-align: left; font-weight: 400; }
        th { width: 38%; }
        .photos td { width: 50%; border: 0; padding: 4px 6px 8px 0; page-break-inside: avoid; }
        .photos img { width: 78mm; height: auto; max-height: 70mm; }
        .signatures td { width: 50%; border: 0; padding: 8px 8px 0 0; page-break-inside: avoid; }
        .signature-line { height: 16mm; border-bottom: 1px solid #000; margin: 6px 0 4px; }
        .signature-line img { height: 14mm; width: auto; }
        .muted { font-size: 10pt; }
        .narrative { margin: 0 0 8px; white-space: pre-wrap; }
        .footer { position: fixed; left: 0; right: 0; bottom: -14mm; font-size: 9pt; }
        .footer .ref { float: left; }
    </style>
</head>
<body>
    <div class="footer"><span class="ref">{{ $reference }}</span></div>
    @if ($draft)
        <p class="draft">DRAFT</p>
    @endif
    <div class="logos">
        @foreach ($logos as $logo)
            <img src="{{ $logo['path'] }}" alt="{{ $logo['alt'] }}">
        @endforeach
    </div>
    <p class="center">Republic of the Philippines</p>
    <p class="center">Province of Surigao del Sur</p>
    <p class="center">City of Tandag</p>
    <p class="center"><strong>Barangay {{ $barangay_name }}</strong></p>
    <h1 class="center">{{ $title }}</h1>
    <p class="center">{{ $status }}</p>

    <h2>Report identification</h2>
    <table class="record">
        <thead><tr><th>Field</th><th>Recorded details</th></tr></thead>
        <tbody>
            <tr><th>Report reference</th><td>{{ $reference }}</td></tr>
            <tr><th>Status</th><td>{{ $status }}</td></tr>
            <tr><th>Submitted</th><td>{{ $submitted_at }}</td></tr>
            @if ($return_reason !== 'Not recorded')
                <tr><th>Correction requested</th><td>{{ $return_reason }}</td></tr>
            @endif
        </tbody>
    </table>

    <h2>Resident</h2>
    <table class="record">
        <thead><tr><th>Field</th><th>Recorded details</th></tr></thead>
        <tbody>
            <tr><th>Name</th><td>{{ $resident_name }}</td></tr>
            <tr><th>Role</th><td>Reporting Resident</td></tr>
            <tr><th>Barangay</th><td>{{ $barangay_name }}</td></tr>
        </tbody>
    </table>

    <h2>Incident</h2>
    <table class="record">
        <thead><tr><th>Field</th><th>Recorded details</th></tr></thead>
        <tbody>
            <tr><th>Incident</th><td>{{ $incident_name }}</td></tr>
            <tr><th>Type</th><td>{{ $incident_type }}</td></tr>
            <tr><th>Official date</th><td>{{ $occurred_at }}</td></tr>
            <tr><th>Observed</th><td>{{ $observed_at }}@if ($observed_approximate) (approximate)@endif</td></tr>
        </tbody>
    </table>

    <h2>Location and description</h2>
    <table class="record">
        <thead><tr><th>Field</th><th>Recorded details</th></tr></thead>
        <tbody>
            <tr><th>Reported location</th><td>{{ $reported_location }}</td></tr>
        </tbody>
    </table>
    <p><strong>What happened</strong></p>
    <div class="narrative">{{ $description }}</div>

    <h2>Photographic evidence</h2>
    @if (count($photos) === 0)
        <p>No photographs were saved with this report.</p>
    @else
        <table class="photos">
            @foreach (array_chunk($photos, 2) as $pair)
                <tr>
                    @foreach ($pair as $photo)
                        <td>
                            @if ($photo['path'])
                                <img src="{{ $photo['path'] }}" alt="{{ $photo['caption'] }}">
                            @else
                                <p>Photograph file is unavailable.</p>
                            @endif
                            <p>{{ $photo['caption'] }}</p>
                        </td>
                    @endforeach
                    @if (count($pair) === 1)
                        <td></td>
                    @endif
                </tr>
            @endforeach
        </table>
    @endif

    <h2>Assessment</h2>
    <p>{{ $assessment['summary'] }}</p>
    @if ($assessment['finding'])
        <p>Recorded finding: {{ $assessment['finding'] }}</p>
    @endif

    <h2>Resident declaration</h2>
    <table class="signatures">
        <tr>
            <td>
                <strong>Reporting Resident</strong>
                <div class="signature-line">
                    @if ($signature['image'])
                        <img src="{{ $signature['image'] }}" alt="Recorded resident signature">
                    @endif
                </div>
                <div>{{ $signature['name'] }}</div>
                @if ($signature['statement'])
                    <div class="muted">{{ $signature['statement'] }}</div>
                @endif
                <div class="muted">{{ $signature['caption'] }}</div>
                @if ($signature['signed_at'])
                    <div class="muted">{{ $signature['signed_at'] }}</div>
                @endif
            </td>
        </tr>
    </table>

    <h2>Office endorsements</h2>
    <p class="muted">A pending line is the configured officeholder. It is not a signature on this report. A completed line names the person who signed the linked document.</p>
    <table class="signatures">
        @foreach (array_chunk($endorsements, 2) as $pair)
            <tr>
                @foreach ($pair as $endorsement)
                    <td>
                        <strong>{{ $endorsement['title'] }}</strong>
                        <div class="signature-line">
                            @if ($endorsement['image'])
                                <img src="{{ $endorsement['image'] }}" alt="Recorded endorsement signature">
                            @endif
                        </div>
                        <div>{{ $endorsement['name'] }}</div>
                        <div class="muted">{{ $endorsement['position'] }}</div>
                        <div class="muted">{{ $endorsement['caption'] }}</div>
                        @if ($endorsement['document'])
                            <div class="muted">{{ $endorsement['document'] }}</div>
                        @endif
                    </td>
                @endforeach
            </tr>
        @endforeach
    </table>
</body>
</html>
