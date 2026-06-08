@if(config('services.meta.pixel_id'))
{{-- Meta Pixel — promotes layout.ai; fires PageView everywhere, and a deduped
     Lead on the page shown right after signup (server-side CAPI sends the
     matching Lead with the same eventID). --}}
<script>
  !function(f,b,e,v,n,t,s)
  {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
  n.callMethod.apply(n,arguments):n.queue.push(arguments)};
  if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
  n.queue=[];t=b.createElement(e);t.async=!0;
  t.src=v;s=b.getElementsByTagName(e)[0];
  s.parentNode.insertBefore(t,s)}(window,document,'script',
  'https://connect.facebook.net/en_US/fbevents.js');
  fbq('init', '{{ config('services.meta.pixel_id') }}');
  fbq('track', 'PageView');
  @if(session('meta_lead_event_id'))
  fbq('track', 'Lead', {}, {eventID: '{{ session('meta_lead_event_id') }}'});
  @endif
</script>
<noscript><img height="1" width="1" style="display:none"
  src="https://www.facebook.com/tr?id={{ config('services.meta.pixel_id') }}&ev=PageView&noscript=1"/></noscript>
@endif
