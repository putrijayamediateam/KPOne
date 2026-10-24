<!DOCTYPE html>
<html lang="{{ $htmlLanguage }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>{{ $title }} — {{ $wordmark }}</title>
<meta name="description" content="{{ $description }}">
<link rel="canonical" href="{{ $canonical }}">
<link rel="alternate" hreflang="{{ $htmlLanguage }}" href="{{ $canonical }}">
<link rel="alternate" hreflang="{{ $language === 'en' ? 'ms' : 'en' }}" href="{{ $alternate }}">
<meta property="og:title" content="{{ $title }} — {{ $wordmark }}">
<meta property="og:description" content="{{ $description }}">
<meta property="og:url" content="{{ $canonical }}">
<meta property="og:type" content="website">
<link rel="stylesheet" href="{{ $relative('assets/site.css') }}">
<script src="{{ $relative('assets/site.js') }}" defer></script>
</head>
<body data-page="{{ $type }}" data-language="{{ $language }}" data-root="{{ $relative('') }}" data-catalog="{{ $relative('data/services'.($language === 'bm' ? '.bm' : '').'.json') }}" data-wordmark="{{ $wordmark }}">
<a class="skip" href="#main">{{ $language === 'en' ? 'Skip to content' : 'Langkau ke kandungan' }}</a>
<div role="banner" class="site-header">
<div class="masthead">
<a class="wordmark" href="{{ $relative(reset($routes)) }}">
@if($site['logo'])<img src="{{ $relative($site['logo']['path']) }}" alt="{{ $site['logo'][$language] }}" width="{{ $site['logo']['width'] }}" height="{{ $site['logo']['height'] }}">@endif
{{ $wordmark }}</a>
<a class="language" href="{{ $switchUrl }}" lang="{{ $language === 'en' ? 'ms' : 'en' }}" aria-label="{{ $language === 'en' ? 'Bahasa Melayu' : 'English' }}">{{ $language === 'en' ? 'BM' : 'EN' }}</a>
</div>
<div role="navigation" aria-label="{{ $language === 'en' ? 'Main navigation' : 'Navigasi utama' }}"><ul>
@foreach($routes as $key=>$target)<li><a href="{{ $relative($target) }}" @if($type === $key)aria-current="page"@endif>{{ $site['navigation'][$key][$language] }}</a></li>@endforeach
</ul></div>
</div>
<div role="main" id="main" tabindex="-1">
<div class="page-heading"><p class="eyebrow">{{ $wordmark }}</p><h1>{{ $title }}</h1>
@if($type === 'home')<p class="lead">{{ $homeLine }}</p>@endif</div>
@if($type === 'home')
<div aria-labelledby="home-branches"><h2 id="home-branches">{{ $language === 'en' ? 'Our branches' : 'Cawangan kami' }}</h2><div class="cards">
@foreach($groups['branch_public_profile'] ?? [] as $item)@include('partials.card',['item'=>$item,'itemType'=>'branch_public_profile'])@endforeach
</div></div>
@if(!empty($groups['public_service']))<div aria-labelledby="home-services"><h2 id="home-services">{{ $language === 'en' ? 'Services' : 'Perkhidmatan' }}</h2><div class="cards">
@foreach(array_slice($groups['public_service'],0,6) as $item)@include('partials.card',['item'=>$item,'itemType'=>'public_service'])@endforeach
</div></div>@endif
@if(isset($routes['little-shield']) && !empty($groups['little_shield']))<div class="programme-teaser"><h2>{{ $groups['little_shield'][0]['title'] }}</h2><p>{{ $groups['little_shield'][0]['tagline'] ?? '' }}</p><a class="button" href="{{ $relative($routes['little-shield']) }}">{{ $language === 'en' ? 'Explore Little Shield' : 'Kenali Little Shield' }}</a></div>@endif
@elseif($type === 'branch')
@include('partials.card',['item'=>$branch,'itemType'=>'branch_public_profile','detail'=>true])
@elseif(in_array($type,['branches','contact']))
<div class="cards">@foreach($groups['branch_public_profile'] ?? [] as $item)@include('partials.card',['item'=>$item,'itemType'=>'branch_public_profile'])@endforeach</div>
@elseif($type === 'services')
<div class="filter"><label for="service-category">{{ $language === 'en' ? 'Service category' : 'Kategori perkhidmatan' }}</label><select id="service-category"><option value="">{{ $language === 'en' ? 'All categories' : 'Semua kategori' }}</option>@foreach($groups['service_category'] ?? [] as $category)<option value="{{ $category['alias'] }}">{{ $category['name'] }}</option>@endforeach</select><p id="filter-status" role="status"></p></div>
<div class="cards" id="service-list">@foreach($groups['public_service'] ?? [] as $item)@include('partials.card',['item'=>$item,'itemType'=>'public_service'])@endforeach</div>
@elseif($type === 'service-detail')
<div id="service-detail" aria-live="polite"><p>{{ $language === 'en' ? 'Select a service from the services page.' : 'Pilih perkhidmatan daripada halaman perkhidmatan.' }}</p></div>
<p><a href="{{ $relative($routes['services']) }}">{{ $language === 'en' ? 'All services' : 'Semua perkhidmatan' }}</a></p>
<noscript><p>{{ $language === 'en' ? 'JavaScript is needed to show the selected service. The services list remains available.' : 'JavaScript diperlukan untuk memaparkan perkhidmatan yang dipilih. Senarai perkhidmatan masih tersedia.' }}</p></noscript>
@elseif($type === '404')
<p>{{ $language === 'en' ? 'This page is unavailable. Use the navigation to find clinic information.' : 'Halaman ini tidak tersedia. Gunakan navigasi untuk mencari maklumat klinik.' }}</p>
@else
@php($itemType = ['doctors'=>'doctor_profile','careers'=>'career_posting','promotions'=>'promotion','activities'=>'activity','faqs'=>'faq','little-shield'=>'little_shield'][$type])
<div class="{{ $type === 'little-shield' ? 'programme' : 'cards' }}">@foreach($groups[$itemType] ?? [] as $item)@include('partials.card',['item'=>$item,'itemType'=>$itemType])@endforeach</div>
@endif
</div>
<div class="site-footer" role="contentinfo" itemscope itemtype="https://schema.org/MedicalClinic"><p itemprop="name">{{ $wordmark }}</p>@if($site['footer'][$language] !== '')<p>{{ $site['footer'][$language] }}</p>@endif</div>
</body>
</html>
