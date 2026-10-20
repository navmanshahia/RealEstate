<?php
declare(strict_types=1);

/** Extract reviewable, non-authoritative fields from a provider's public JSON-LD. */
function listing_extract_fields(DOMDocument $document,array &$draft): void {
    $nodes=[];
    foreach($document->getElementsByTagName('script') as $script){
        if(strtolower(trim($script->getAttribute('type')))!=='application/ld+json')continue;
        $raw=$script->textContent;
        if(strlen($raw)>120000)continue;
        $root=json_decode($raw,true,30);
        if(is_array($root))$nodes[]=$root;
    }
    $seen=0;
    while($nodes && $seen++<100){
        $node=array_shift($nodes);
        if(!is_array($node))continue;
        if(array_is_list($node)){foreach($node as $part)if(is_array($part))$nodes[]=$part;continue;}
        foreach(['@graph','mainEntity','itemOffered'] as $key)if(isset($node[$key])&&is_array($node[$key]))$nodes[]=$node[$key];
        $types=$node['@type']??[];
        if(!is_array($types))$types=[$types];
        $types=strtolower(implode(' ',array_filter($types,'is_string')));
        // Never let a brokerage organisation's head-office address override a home address.
        $isProperty=(bool)preg_match('/house|residence|apartment|realestatelisting|singlefamily|product|accommodation|place/',$types);
        if(!$isProperty)continue;
        if(!empty($node['name'])&&$draft['name']==='Imported listing (review details)')$draft['name']=clean($node['name'],180);
        if(empty($draft['description'])&&!empty($node['description'])&&is_string($node['description']))$draft['description']=clean($node['description'],3000);
        if(empty($draft['image'])){
            $image=$node['image']??null;
            if(is_array($image))$image=$image['url']??$image[0]??null;
            if(is_string($image)&&str_starts_with($image,'https://'))$draft['image']=clean($image,1000);
        }
        $address=$node['address']??null;
        if(is_array($address)&&empty($draft['location'])){
            $pieces=[];
            foreach(['streetAddress','addressLocality','addressRegion','postalCode'] as $key)if(!empty($address[$key])&&is_string($address[$key]))$pieces[]=$address[$key];
            if($pieces)$draft['location']=clean(implode(', ',$pieces),250);
        }
        if(empty($draft['price'])){
            $offer=$node['offers']??[];
            if(isset($offer[0]))$offer=$offer[0];
            if(is_array($offer)){
                $amount=$offer['price']??$offer['priceSpecification']['price']??'';
                $currency=strtoupper((string)($offer['priceCurrency']??$offer['priceSpecification']['priceCurrency']??'CAD'));
                if($currency==='CAD'&&is_numeric(str_replace(',','',(string)$amount)))$draft['price']=(float)str_replace(',','',(string)$amount);
            }
        }
        foreach(['numberOfBedrooms'=>'beds','numberOfBathroomsTotal'=>'baths'] as $source=>$field){
            $val=$node[$source]??null;
            if($val!==null&&is_numeric($val)&&$val>=0&&$val<100)$draft[$field]=(float)$val;
        }
        $area=$node['floorSize']??$node['floorArea']??null;
        if(is_array($area)){
            $size=$area['value']??null;$unit=strtoupper((string)($area['unitCode']??$area['unitText']??''));
            if(is_numeric($size)&&$size>0&&$size<1000000){
                if(in_array($unit,['FTK','SQFT','SQ FT','SQUARE FEET',''],true))$draft['area']=(float)$size;
                if(in_array($unit,['MTK','SQM','M2'],true))$draft['area']=round((float)$size*10.7639104,1);
            }
        }
        if(str_contains($types,'apartment'))$draft['type']='Condo';
        elseif(str_contains($types,'house')||str_contains($types,'singlefamily'))$draft['type']='House';
    }
}
