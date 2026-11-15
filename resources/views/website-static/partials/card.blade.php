<div role="article" class="card" id="{{ $item['anchor'] }}" @if($itemType === 'public_service')data-category="{{ $item['parent'] ?? '' }}"@endif>
@if(!empty($item['media']))<div class="gallery">@foreach($item['media'] as $image)<img src="{{ $relative($image['path']) }}" alt="{{ $image['alt'] }}" width="{{ $image['width'] }}" height="{{ $image['height'] }}" loading="lazy">@endforeach</div>@endif
<h2>@if($itemType === 'branch_public_profile' && isset($routes['branches']) && empty($detail))<a href="{{ $relative(($language === 'bm' ? 'bm/' : '').$item['alias'].'/index.html') }}">{{ $item['display_name'] }}</a>@elseif($itemType === 'public_service' && isset($routes['services']))<a href="{{ $relative(($language === 'bm' ? 'bm/' : '').'service-detail/index.html') }}?slug={{ $item['alias'] }}">{{ $item['name'] }}</a>@else{{ $item['display_name'] ?? $item['name'] ?? $item['title'] ?? $item['question'] }}@endif</h2>
@foreach(['professional_title','public_registration_text','tagline','short_summary','summary','biography','answer','introduction','offer_text','price_text','location_note','partnership_introduction','how_to_take_part'] as $field)
@if(!empty($item[$field]))<p class="prose">{{ $item[$field] }}</p>@endif
@endforeach
@foreach(['valid_from','valid_until','starts_on','ends_on','closing_date','employment_type'] as $field)
@if(!empty($item[$field]))<p><strong>{{ ['en'=>['valid_from'=>'Valid from','valid_until'=>'Valid until','starts_on'=>'Starts','ends_on'=>'Ends','closing_date'=>'Closing date','employment_type'=>'Employment type'],'bm'=>['valid_from'=>'Sah dari','valid_until'=>'Sah sehingga','starts_on'=>'Bermula','ends_on'=>'Berakhir','closing_date'=>'Tarikh tutup','employment_type'=>'Jenis pekerjaan']][$language][$field] }}:</strong> {{ str_replace('_',' ',$item[$field]) }}</p>@endif
@endforeach
@foreach(['qualifications','areas_of_practice','languages_spoken','responsibilities','requirements','overview_paragraphs','medical_safeguards','terms','what_to_expect','suitable_for'] as $field)
@if(!empty($item[$field]))<ul>@foreach($item[$field] as $line)<li class="prose">{{ $line }}</li>@endforeach</ul>@endif
@endforeach
@foreach(['description_sections','programme_pillars','programme_flow_steps','partner_types'] as $field)
@foreach($item[$field] ?? [] as $section)<div><h3>{{ $section['heading'] ?? $section['title'] }}</h3><p class="prose">{{ $section['body'] ?? $section['description'] }}</p></div>@endforeach
@endforeach
@if($itemType === 'branch_public_profile')
<address>@foreach(['address_line_1','address_line_2','postcode','city','state'] as $field)@if(!empty($item[$field]))<span>{{ $item[$field] }}</span>@endif @endforeach</address>
@foreach(['public_phone','hours_note','parking_notes','accessibility_notes'] as $field)@if(!empty($item[$field]))<p class="prose">{{ $item[$field] }}</p>@endif @endforeach
@if(!empty($item['public_email']))<p><a href="mailto:{{ $item['public_email'] }}">{{ $item['public_email'] }}</a></p>@endif
<div class="contact-links">@if(!empty($item['whatsapp_url']))<a class="button" href="{{ $item['whatsapp_url'] }}" rel="noreferrer">WhatsApp</a>@endif @if(!empty($item['map_url']))<a href="{{ $item['map_url'] }}" rel="noreferrer">{{ $language === 'en' ? 'Map and directions' : 'Peta dan arah' }}</a>@endif</div>
<dl class="hours">@foreach($item['opening_hours'] ?? [] as $day=>$hours)<div><dt>{{ ['en'=>['mon'=>'Monday','tue'=>'Tuesday','wed'=>'Wednesday','thu'=>'Thursday','fri'=>'Friday','sat'=>'Saturday','sun'=>'Sunday'],'bm'=>['mon'=>'Isnin','tue'=>'Selasa','wed'=>'Rabu','thu'=>'Khamis','fri'=>'Jumaat','sat'=>'Sabtu','sun'=>'Ahad']][$language][$day] }}</dt><dd>{{ $hours['closed'] ? ($language === 'en' ? 'Closed' : 'Tutup') : $hours['open'].' – '.$hours['close'] }}</dd></div>@endforeach</dl>
@foreach($item['contact_blocks'] ?? [] as $block)<p><strong>{{ $block['label'] }}:</strong> {{ $block['text'] }}</p>@endforeach
@endif
@if($itemType === 'career_posting')<p>@if(!empty($item['apply_url']))<a class="button" href="{{ $item['apply_url'] }}" rel="noreferrer">{{ $language === 'en' ? 'How to apply' : 'Cara memohon' }}</a>@elseif(!empty($item['apply_email']))<a href="mailto:{{ $item['apply_email'] }}">{{ $item['apply_email'] }}</a>@endif</p>@endif
@if($itemType === 'faq' && !empty($item['linked_service']) && isset($routes['services']))<a href="{{ $relative(($language === 'bm' ? 'bm/' : '').'service-detail/index.html') }}?slug={{ $item['linked_service'] }}">{{ $language === 'en' ? 'Related service' : 'Perkhidmatan berkaitan' }}</a>@endif
</div>
