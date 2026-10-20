<?php
declare(strict_types=1);
if(!class_exists('DOMDocument')){echo "SKIP: DOM extension not installed\n";exit(0);}
function clean(mixed $s,int $max=200):string{return mb_substr(trim((string)$s),0,$max);}
require dirname(__DIR__).'/app/listing_metadata.php';
$html='<!doctype html><script type="application/ld+json">'.json_encode([
 '@context'=>'https://schema.org','@type'=>'House','name'=>'Harbour View Home',
 'address'=>['@type'=>'PostalAddress','streetAddress'=>'123 Marine Drive','addressLocality'=>'Vancouver','addressRegion'=>'BC','postalCode'=>'V6B 1A1'],
 'offers'=>['@type'=>'Offer','price'=>'1250000','priceCurrency'=>'CAD'],
 'numberOfBedrooms'=>3,'numberOfBathroomsTotal'=>2,'floorSize'=>['value'=>185,'unitCode'=>'MTK']
]).'</script>';
$doc=new DOMDocument();
$doc->loadHTML($html);
$d=['name'=>'Imported listing (review details)','description'=>'','location'=>'','price'=>'','image'=>'','published'=>false];
listing_extract_fields($doc,$d);
foreach(['name'=>'Harbour View Home','price'=>1250000.0,'beds'=>3.0,'baths'=>2.0,'type'=>'House'] as $key=>$value){
 if(($d[$key]??null)!==$value)throw new RuntimeException('Wrong '.$key.': '.var_export($d[$key]??null,true));
}
if(!str_contains($d['location'],'Vancouver')||abs($d['area']-1991.32)>1)throw new RuntimeException('Address/area parsing failed');
if($d['published']!==false)throw new RuntimeException('Imported listings must never be auto-published');
echo "PASS: listing metadata is reviewable and unpublished\n";
