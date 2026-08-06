{{-- Weekly digest, HTML part — rendered through core's branded email layout
     (x-mail::html: forum logo/title header, standard chrome) so it looks like
     every other email the forum sends. Greeting/signoff suppressed: a digest
     is a report, not a letter. All data is prepared by DigestCommand. --}}
<x-mail::html :greeting="false" :signoff="false">
    <x-slot:header>
        <h2 style="margin:10px 0 0;font-size:18px;">{{ $title }}</h2>
    </x-slot:header>

    <x-slot:content>
        <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;margin:6px 0 4px;">
            @foreach ($stats as $row)
                <tr>
                    <td style="padding:7px 0;color:#555;border-bottom:1px solid #f0f0f0;">{{ $row['label'] }}</td>
                    <td style="padding:7px 0;text-align:right;font-weight:600;border-bottom:1px solid #f0f0f0;">{{ $row['count'] }}</td>
                    <td style="padding:7px 0 7px 14px;text-align:right;color:{{ $row['color'] }};width:64px;border-bottom:1px solid #f0f0f0;">{{ $row['change'] }}</td>
                </tr>
            @endforeach
        </table>

        @if ($topDiscussion)
            <p style="margin:16px 0 2px;">
                <span style="color:#555;">{{ $topDiscussionLabel }}:</span>
                <strong>&ldquo;{{ $topDiscussion['label'] }}&rdquo;</strong>
                <span style="color:#999;">&middot; {{ $topDiscussion['suffix'] }}</span>
            </p>
        @endif

        @if ($topSearch)
            <p style="margin:6px 0 0;">
                <span style="color:#555;">{{ $topSearchLabel }}:</span>
                <strong>&ldquo;{{ $topSearch['label'] }}&rdquo;</strong>
                <span style="color:#999;">&middot; {{ $topSearch['suffix'] }}</span>
            </p>
        @endif
    </x-slot:content>

    <x-slot:footer>
        <p>{{ $footerText }}</p>
    </x-slot:footer>
</x-mail::html>
