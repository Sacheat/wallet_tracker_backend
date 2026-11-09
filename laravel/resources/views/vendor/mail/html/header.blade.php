@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
<table role="presentation" cellpadding="0" cellspacing="0" align="center">
<tr>
<td style="vertical-align: middle;"><img src="{{ url('images/logo-email.png') }}" class="logo" width="32" height="32" alt=""></td>
<td class="brand-name">{!! $slot !!}</td>
</tr>
</table>
</a>
</td>
</tr>
