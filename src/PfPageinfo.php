<?php
// Author: Peter Forret (pforret, peter@forret.com)
namespace Pforret\PfPageinfo;

class PfPageinfo
{
    private $user_agent="Mozilla/5.0 (Macintosh; Intel Mac OS X 10_14_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/76.0.3809.132 Safari/537.36";
    var $cache;
    var $maxsecs;
    var $timeout;
    var $cache_folder;

    function __construct($folder="cache",$max_age=300,$timeout=30){
        $this->cache_folder=$folder;
        $this->cache=New Cache($folder,$max_age);
        $this->timeout=$timeout;
        $this->binexts=explode(";","pdf;doc;xls;docx;xlsx");
    }

    function get($url,$use_cookie=false,$headers_only=false){
        trace("GURL::get [$url]");
        if(!$url)    return "";
        $maxlen=500;
        $cid="$url|GET";
        $mid="$url|GET:META";
        $hid="$url|GET:HEADERS";
        $meta=Array();
        $content=$this->cache->get($mid);
        if($content){
            $data=Array();
            $data["meta"]=$this->cache->get($mid);
            if(!$headers_only){
                $data["html"]=$this->cache->get($cid);
            }
            return $data;
        }
        $urlparts=parse_url($url);
        $domain=$urlparts["host"];
        $extension="";
        if(isset($urlparts["path"]))    $extension=strtolower(pathinfo($urlparts["path"],PATHINFO_EXTENSION));
        if(in_array($extension,$this->binexts)){
            $html="";
            $cfile=$this->cache->cache_file($cid);
            $fc=fopen($cfile,"w");
            $ch = curl_init();
            curl_setopt ($ch, CURLOPT_URL, $url);
            curl_setopt ($ch, CURLOPT_USERAGENT, $this->user_agent);
            curl_setopt ($ch, CURLOPT_HEADER, 0);
            curl_setopt ($ch, CURLOPT_FOLLOWLOCATION, 1);
            curl_setopt ($ch, CURLOPT_TIMEOUT,$this->timeout);
            curl_setopt ($ch, CURLOPT_CONNECTTIMEOUT,$this->timeout);
            curl_setopt ($ch, CURLOPT_FILETIME,1);
            curl_setopt ($ch, CURLOPT_FILE,$fc); // save to file directly
            if($use_cookie){
                $cookie_file=$this->cache_folder . "/ck_$domain.txt";
                curl_setopt ($ch, CURLOPT_COOKIEJAR, $cookie_file);
                curl_setopt ($ch, CURLOPT_REFERER, $url);
            }
            curl_exec ($ch);
            trace(curl_getinfo($ch));
            switch($extension){
                case "pdf":
                    $rawlines=cmdline("pdftotext -l 5 $cfile -");
                    if($rawlines){
                        $text="";
                        foreach($rawlines as $line){
                            if(strlen($text)> $maxlen)  continue;
                            if(strlen(trim($line)) > 30){
                                $text.="$line\n";
                            }
                        }
                        $text=substr($text,0,$maxlen);
                    } else {
                        $text="";
                    }
                    $meta["content_text"]=$text;
                    $imgurl=$this->cache->make_cache_name($url,"jpg");
                    cmdline("convert -density 100 $cfile\[0\] -quality 90 $imgurl");
                    $meta["meta_image"]=$imgurl;

                    break;
            }

        } else {
            $ch = curl_init();
            curl_setopt ($ch, CURLOPT_URL, $url);
            curl_setopt ($ch, CURLOPT_USERAGENT, $this->user_agent);
            curl_setopt ($ch, CURLOPT_HEADER, 0);
            curl_setopt ($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt ($ch, CURLOPT_FOLLOWLOCATION, 1);
            curl_setopt ($ch, CURLOPT_TIMEOUT,$this->timeout);
            curl_setopt ($ch, CURLOPT_CONNECTTIMEOUT,$this->timeout);
            curl_setopt ($ch, CURLOPT_FILETIME,1);
            if($use_cookie){
                $cookie_file=$this->cache_folder . "/ck_$domain.txt";
                curl_setopt ($ch, CURLOPT_COOKIEJAR, $cookie_file);
                curl_setopt ($ch, CURLOPT_REFERER, $url);
            }
            $html = curl_exec ($ch);
            if(!$html)  return false;
            trace("------ CURL");
            trace(curl_getinfo($ch));
            $cfile=$this->cache->set($cid,$html);
            $head=substr($html,0,stripos($html,"</head>"));
            $head=str_replace('data-rh="true" ','',$head);
            $hfile=$this->cache->set($hid,$head);
            trace("PARSE META TAGS");
            $meta_from_html=$this->get_meta_tags($html);
            trace($meta_from_html);
            $meta["meta_description"]=$this->get_meta_value($meta_from_html,["description","twitter:description","sailthru_description","og:description"]);
            $meta["meta_title"]=$this->get_meta_value($meta_from_html,["title","twitter:title","sailthru_title","sailthru.title","og:title","analyticsattributes_title"]);
            $meta["html_title"]=getfromxml($head,"title");
            $meta["title"]="";
            if($meta["html_title"]) $meta["title"]=$meta["html_title"];
            if($meta["meta_title"]) $meta["title"]=$meta["meta_title"];
            $meta["meta_author"]=$this->get_meta_value($meta_from_html,["author","twitter:creator","sailthru_author","og:article:author","analyticsattributes_author","byl","twitter:site"]);

            $meta["meta_image"]=$this->get_meta_value($meta_from_html,["image","twitter:image","sailthru.image.full","twitter:image:src","og:image","sailthru.image.thumb","thumbnail"]);
            if(!isset($meta["meta_image"]) OR !$meta["meta_image"]){
                $meta["meta_image"]=$this->extract_image($html,$url);
            } else {
                $meta["meta_image"]=$this->full_url($meta["meta_image"],$url);
            }
            if($meta["meta_image"] AND substr($meta["meta_image"],-4,4) == ".zip"){
                $meta["meta_image"]=false;
            }
            $meta["meta_keywords"]=$this->get_meta_value($meta_from_html,["keywords","news_keywords","analyticsAttributes.keywords","og:article:tag","sailthru_tags"]);
            //$meta["meta_favicon"]=$this->get_meta_value($meta_from_html,["msapplication-tileimage","msapplication-square70x70logo","msapplication-square150x150logo"]);
            $meta["meta_date"]=$this->get_meta_value($meta_from_html,["revision_date","REVISION_DATE","article:modified","article:modified_time","article:published","sailthru_date","og:article:modified_time","article:published_time"]);
            $meta["meta_time"]="";
            if($meta["meta_date"]){
                $meta["meta_time"]=strtotime($meta["meta_date"]);
            }
            $meta=array_merge($meta,$this->get_link_tags($html,$url));

            $text_data=$this->extract_text($url,$html,$maxlen,$meta["meta_title"]);
            $meta["content_text"]=$text_data["text"];
            $meta["content_method"]=$text_data["method"];
        }
        if(curl_getinfo($ch,CURLINFO_FILETIME) > 0){
            $meta["update_time"]=curl_getinfo($ch,CURLINFO_FILETIME);
        } else {
            $meta["update_time"]=time();
        }
        $meta["update_date"]=date("c",$meta["update_time"]);
        $meta["url_original"]=$url;
        $meta["url_effective"]=curl_getinfo($ch,CURLINFO_EFFECTIVE_URL);
        $meta["content_type"]=curl_getinfo($ch,CURLINFO_CONTENT_TYPE );
        $meta["redirect_count"]=curl_getinfo($ch,CURLINFO_REDIRECT_COUNT );
        $meta["response_code"]=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
        $meta["size_download"]=curl_getinfo($ch,CURLINFO_SIZE_DOWNLOAD );
        $meta["speed_download"]=curl_getinfo($ch,CURLINFO_SPEED_DOWNLOAD );
        //$meta["speed_upload"]=curl_getinfo($ch,CURLINFO_SPEED_UPLOAD );
        $meta["total_time"]=curl_getinfo($ch,CURLINFO_TOTAL_TIME);
        curl_close($ch);
        ksort($meta);
        trace($meta);
        $this->cache->set($mid,$meta);

        $data=Array();
        $data["meta"]=$meta;
        if(!$headers_only){
            $data["html"]=$html;
        }
        return $data;
    }

    private function get_meta_value($meta_list,$keys,$default=false){
        $return=false;
        if(!is_array($keys)){
            $keys=Array($keys);
        }
        foreach($keys as $key){
            if($return) continue;
            if(isset($meta_list[$key]) AND $meta_list[$key]){
                $return=trim($meta_list[$key]);
                trace("get_meta_value: found key [$key]: $return");
            }
        }
        if(!$return){
            $return=$default;
            trace("get_meta_value: used default: $return (no [" . implode(", ",$keys). "])");
        }
        return $return;
    }

    function post($url,$payload){

    }

    function get_link_tags($html,$url){
        //trace("get_link_tags");
        $result=Array();
        preg_match_all("|(<link [^>]*>)|",$html,$matches);
        if($matches){
            //trace($matches);
            foreach($matches[0] as $match){
                $match=str_replace("'",'"',$match);
                $rel=quickmatch($match,"|rel=\"([^\"]+)\"|");
                $type=quickmatch($match,"|type=\"([^\"]+)\"|");
                $href=quickmatch($match,"|href=\"([^\"']+)\"|");
                $size=quickmatch($match,"|sizes=\"([^\"']+)\"|");
                if(substr($href,0,2) === "//"){
                    $href=parse_url($url,PHP_URL_SCHEME) .":". $href;
                }
                if(substr($href,0,1) === "/"){
                    $href=parse_url($url,PHP_URL_SCHEME) . "://" . parse_url($url,PHP_URL_HOST) . $href;
                }
                switch(true){
                    case $rel and $rel=="stylesheet" AND $href:
                        $type="stylesheet";
                        $result["$type"][]=$this->full_url($href,$url);
                        break;
                    case $rel and $rel=="alternate" AND $href:
                        $type=str_replace("application/","",$type);
                        $result["$type"][]=$this->full_url($href,$url);
                        break;
                    case $rel and $rel=="icon" AND $href:
                    case $rel and $rel=="apple-touch-icon" AND $href:
                    case $rel and $rel=="shortcut icon" AND $href:
                    case $rel and $rel=="mask-icon" AND $href:
                        if(contains($size,"x")){
                            list($size,$same)=explode("x",$size);
                            $result["icon"][$size]=$this->full_url($href,$url);
                        } else {
                            $result["icon"][0]=$this->full_url($href,$url);
                        }
                        break;
                    case $rel AND $href:
                        $result["link_$rel"]=$this->full_url($href,$url);
                        break;
                }
            }
        }
        //trace($result);
        return $result;
    }

    function extract_text($url,$html,$max_chars=300,$title=false){
        $tm=New BFTimer;
        $origsize=strlen($html);
        $domain=parse_url($url,PHP_URL_HOST);
        trace(["extract_text: [$url] => [$domain]","extract_text: input = $origsize chars"]);
        $text="";
        $extract_method="";
        switch(true){
            case $domain == "en.wikipedia.org":
            case $domain == "it.wikipedia.org":
            case $domain == "es.wikipedia.org":
            case $domain == "de.wikipedia.org":
            case $domain == "nl.wikipedia.org":
            case $domain == "fr.wikipedia.org":
                trace("$domain: use Wikipedia API");
                // extract keyword from https://en.wikipedia.org/wiki/Donald_Trump
                $keyword=basename($url);
                // use https://en.wikipedia.org/w/api.php?action=query&format=json&titles=Donald_Trump&prop=extracts&exintro&explaintext
                $json=graburl("https://$domain/w/api.php?action=query&format=json&titles=$keyword&prop=extracts&exintro&explaintext");
                if($json){
                    $data=json_decode($json,true);
                    if(isset($data["query"]["pages"])){
                        foreach($data["query"]["pages"] as $id => $data2){
                            $text=$data2["extract"];
                            $extract_method="wikipedia_json";
                        }
                    }
                }
                break;

            case contains($domain,"wordpress.com"):
            case contains($html,'<div class="entry-content">'):
                $cropped=croptext($html,'<div class="entry-content">','<div class="comments">',false);
                $text=Html2Text::convert($cropped, Array(
                        "drop_links" => true,
                        "ignore_errors" => true)
                );
                $extract_method="wordpress-entry";
                break;

            case contains($domain,"quora.com"):
                $cropped=croptext($html,"<div class='question_title'>",'<div class="comments">',false);
                $text=Html2Text::convert($cropped, Array(
                        "drop_links" => true,
                        "ignore_errors" => true)
                );
                $extract_method="quora";
                break;

            case $domain == "www.reddit.com":
                if(contains($html,'<script id="data">')){
                    $cropped=croptext($html,'<script id="data">','</script>',false);
                    $text=quickmatch($cropped,'|"media":{"obfuscated":null,"richtextContent":\{"document":\[\{"c":\[\{"e":"text","t":"([^"]+)"|',false);
                    if(!$text){
                        $text=quickmatch($cropped,'|"richtextContent":\{"document":\[\{"c":\[\{"e":"text","t":"([^"]+)"|',false);
                    }
                }
                $extract_method="reddit_data";
                break;

            case $domain == "medium.com":
            case contains($domain, "medium.com"):
            case contains($html,'android-app://com.medium.reader'):
                $text="";
                $extract_method="medium";
                // skip, this is also in meta description
                break;

            case contains($html,'<meta name="generator" content="WordPress'):
                $cropped=croptext($html,'<div class="post-content">','<!-- .post-content -->',false);
                $text=Html2Text::convert($cropped, Array(
                        "drop_links" => true,
                        "ignore_errors" => true)
                );
                $extract_method="WordPress";
                break;

            case contains($html,'<div class="blog-post">'):
                $cropped=croptext($html,'<div class="blog-post">','<footer',false);
                $text=Html2Text::convert($cropped, Array(
                        "drop_links" => true,
                        "ignore_errors" => true)
                );
                $extract_method="blog-post";
                break;

            case contains($html,'<div class="articleContent">'):
                $cropped=croptext($html,'<div class="articleContent">','<script',false);
                $text=Html2Text::convert($cropped, Array(
                        "drop_links" => true,
                        "ignore_errors" => true)
                );
                $extract_method="articleContent";
                break;

            default:
                $html=str_replace("\n"," ",$html);
                $count1=strlen($html);
                trace("extract_text: input is $count1 characters");
                if(contains($html,'</script>')){
                    trace(substr($html,strpos($html,"<script"),100));
                    $html=preg_replace("|(<script.*</script>)|"," ",$html);
                }
                $html=preg_replace("|(<figcaption.*</figcaption>)|","",$html);
                $html=preg_replace("|(<aside.*</aside>)|","",$html);
                $count2=strlen($html);
                if($count1 <> $count2)  trace("extract_text: input is $count2 characters (after cleanup)");
                $doc = new DOMDocument();
                $doc->loadHTML($html,LIBXML_NOERROR);
                $xpath = new DOMXpath($doc);
                $nodelist=$xpath->query('//*[contains(@class,\'xxxxxxx\')]');
                if(!$nodelist->length)  $nodelist=$xpath->query('//*[contains(@class,\'article\')]');
                if(!$nodelist->length)  $nodelist=$xpath->query('//*[contains(@class,\'main\')]');
                if(!$nodelist->length)  $nodelist=$xpath->query('//*[contains(@class,\'content\')]');
                if(!$nodelist->length)  $nodelist=$xpath->query('//*[contains(@class,\'page-inner\')]');
                if(count($nodelist) >= 1) {
                    $text="";
                    trace("extract_text: found = " . count($nodelist) . " content blocks");
                    $deletes=Array();
                    $deletes[]="This site is protected by reCAPTCHA and the Google Privacy Policy and Terms of Service apply.";
                    $deletes[]="Author:";
                    $deletes[]="Sign up to receive the latest science news.";
                    $deletes[]="Sign up to receive the latest news";
                    $deletes[]="/Getty Images";
                    $deletes[]="Getty Images";
                    if($title)  $deletes[]=$title;
                    foreach($nodelist as $node){
                        $nodetext=$node->textContent;
                        trace($node->getNodePath());
                        foreach ( $node->attributes as $attribute )
                        {
                            trace($attribute->name);
                        }

                        $newtext=Html2Text::convert($nodetext, Array(
                                "drop_links" => true,
                                "ignore_errors" => true)
                        );
                        if(strlen($newtext)>140) {
                            trace("extract_text: Html2Text = [$newtext] (" . strlen($newtext) . " chars)");
                            $text = "$text $newtext";
                        } else {
                            $deletes[]=$newtext;
                        }
                    }
                    trace("deletes");
                    trace($deletes);
                    $text=str_replace(
                        $deletes,
                        "",
                        $text
                    );
                    $text=mb_substr($text,0,$max_chars,'UTF-8')."…";
                    $extract_method="nodelist";
                    trace($text);

                } else {
                    if(contains($html,"<div")){
                        $extract_method="default-div";
                        $text="";
                        $nodes = $doc->getElementsByTagName('div');
                        trace("Found nodes: " . count($nodes));
                        if ( $nodes && 0<$nodes->length ) {
                            foreach($nodes as $node){
                                $newhtml=$node->textContent;
                                $newtext=Html2Text::convert($newhtml, Array(
                                        "drop_links" => true,
                                        "ignore_errors" => true)
                                );
                                trace("[$newhtml] => [$newtext]");
                                if(strlen($newtext) > 32){
                                    $text="$text $newtext";
                                }
                            }
                        }

                    } else {
                        $extract_method="default-dom";
                        $text="";
                        $nodes = $doc->getElementsByTagName('body');
                        if ( $nodes && 0<$nodes->length ) {
                            foreach($nodes as $node){
                                $newhtml=$node->textContent;
                                $newtext=Html2Text::convert($newhtml, Array(
                                        "drop_links" => true,
                                        "ignore_errors" => true)
                                );
                                trace("[$newhtml] => [$newtext]");
                                if(strlen($newtext) > 32){
                                    $text="$text $newtext";
                                }
                            }
                        }

                    }
                }
        }
        $text=str_replace(explode("|","\n|\t|\r")," ",$text);
        $text=str_replace("  "," ",$text);
        $outsize=strlen($text);
        if($outsize>$max_chars){
            $text=txt_shortentext($text,$max_chars);
        }
        $outsize=strlen($text);
        trace($tm->progress_txt($origsize,strlen($text),1,1));
        trace("extract_text: output = [$text] $outsize chars (lt $max_chars)");
        return [
            "text"      => $text,
            "method"    => $extract_method
        ];

    }

    function get_meta_tags($html){
        trace("start get_meta_tags");
        $meta=Array();
        $matches=Array();
        if(stripos($html,"<body")){
            // don't look further than <body
            $html=substr($html,0,stripos($html,"<body"));
        }
        $html=str_replace("\n"," ",$html);
        //trace($html);
        preg_match_all("|<meta ([^>]*)>|", $html,$matches,PREG_SET_ORDER);
        if(!$matches)    return false;
        //trace($matches);
        foreach($matches as $match){
            $key=false;
            $val=false;
            $line=str_replace('"',"'",$match[1]);
            //trace($line);
            $val=quickmatch($line,"|content='([^']+)'|",false);
            if(!$val){
                $val=quickmatch($line,"|content=([^']+)|",false);
            }
            if(!$val)   {
                trace("SKIP: val is empty");
                continue;
            }
            $key=quickmatch($line,"|name='([^']+)'|");
            if(!$key)   $key=quickmatch($line,"|name=([^']+)[\s\t]|");
            if(!$key)   $key=quickmatch($line,"|property='([^']+)'|");
            if(!$key)   $key=quickmatch($line,"|property=([^']+)\s|");
            if(!$key)   $key=quickmatch($line,"|http-equiv='([^']+)'|");
            if(!$key)   $key=quickmatch($line,"|http-equiv=([^']+)\s|");
            if(!$key)   {
                trace("SKIP: key is empty");
                continue;
            }
            if(!isset($meta[$key]) OR !$meta[$key]){
                $meta[$key]=$val;
            } else {
                if(strlen($val) > strlen($meta[$key])){
                    $meta[$key]=$val;
                }
            }
        }
        ksort($meta);
        return $meta;
    }

    function extract_image($html,$url){
        $ldsjon='<script type="application/ld+json"';
        if(contains($html,$ldsjon)){
            $html2=substr($html,strpos($html,$ldsjon)); // cut everything before
            $html2=substr($html2,strpos($html2,">")+1);
            if(contains($html2,"</script>")){
                $html2=substr($html2,0,strpos($html2,"</script>"));
                $json=json_decode($html2,true);
                trace("try via application/ld+json");
                trace($json);
                if(isset($json["itemReviewed"]["image"]["url"]) and $json["itemReviewed"]["image"]["url"]){
                    trace("extract_image: from ld+json");
                    return $json["itemReviewed"]["image"]["url"];
                }
            }
        }
        if(contains($html,' data-large-file="')){
            // for Wordpress
            $link=quickmatch($html,' data-large-file="([^"]*)"' );
            trace("data-large-file:  [$link] ");
            trace("extract_image: from data-large-file");
            return $this->full_url($link,$url);
        }
        if(!contains($html,"<img")){
            trace("extract_image: no <img> found");
            return false;
        }
        preg_match_all("#<img[^>]*src=([^>\s]*)#",$html,$matches );
        if($matches){
            trace("Candidate images found!");
            trace($matches);
            $link=$matches[1][0];
            if(substr($link,-1,1) === '/'){
                $link=substr($link,0,-1);
            }
            $link=str_replace(["'",'"'],"",$link);
            if(strpos($link, 'data:') === 0){
                trace($link);
            } else {
                $link=$this->full_url($link,$url);
            }
            trace("extract_image: [$link] from <img>");
            return $link;
        }
        return false;
    }

    private function startsWith($haystack,$needles){
        if(!$needles) {
            return true;
        }
        if(!is_array($needles)){
            $needles=Array($needles);
        }
        $found=false;
        foreach($needles as $needle){
            if(strpos($haystack, $needle) === 0){
                $found=true;
            }
        }
        return $found;
    }

    private function endsWith($haystack,$needle){
        if(!$needle) {
            return true;
        }
        return substr($haystack,-1 * strlen($needle)) === $needle;
    }

    private function full_url($relative,$base){
        // if empty just return
        if(!$relative)  return $relative;
        // already a full url
        $relative=str_replace("&#x2F;","/",$relative);
        if($this->startsWith($relative,["http://","https://"]))  return $relative;
        // waa actually an absolute URL
        $base_parts=parse_url($base);
        // follow http/https
        if($this->startsWith($relative,["://"]))  return $base_parts["scheme"] . $relative;
        if($this->startsWith($relative,["//"]))  return $base_parts["scheme"] . ":" . $relative;
        $base_domain=$base_parts["host"];
        if(isset($base_parts["port"]))  $base_domain.=":".$base_parts["port"];
        if(isset($base_parts["user"])){
            if(isset($base_parts["pass"])) {
                $base_domain=sprintf("%s:%s@%s",$base_parts["user"],$base_parts["pass"],$base_domain);
            } else {
                $base_domain=sprintf("%s@%s",$base_parts["user"],$base_domain);
            }
        }
        $base_domain=$base_parts["scheme"] ."://" . $base_domain;
        if($this->startsWith($relative,["/"]))  return $base_domain . $relative;
        // /image.png => http://www.example.com/image.png
        if($this->startsWith($relative,["./"]))  $relative=substr($relative,2);
        if(!isset($base_parts["path"])){
            $base_path="/";
        } elseif(isset($base_parts["path"]) AND substr($base_parts["path"],-1,1) == "/"){
            $base_path=$base_parts["path"];
        } else {
            $base_path=dirname($base_parts["path"]) . "/";
        }
        $full_url=$base_domain . $base_path . $relative;
        trace("full_url: start with [$full_url]");
        $full_url=preg_replace("|/[\w\-\_]+/\.\./|","/",$full_url);
        $full_url=preg_replace("|/[\w\-\_]+/\.\./|","/",$full_url);
        $full_url=preg_replace("|/[\w\-\_]+/\.\./|","/",$full_url);
        $full_url=preg_replace("|/[\w\-\_]+/\.\./|","/",$full_url);
        trace("full_url: return with [$full_url]");
        return $full_url;
    }

}
