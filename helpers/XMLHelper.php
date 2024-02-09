<?php

namespace app\helpers;

class XMLHelper {
	public static function findXMLAttribute($xml, $attribute_name) : string {
		if ($xml == null || $attribute_name == null || is_string($xml))
			return '';

		$attributes = $xml->attributes();
		if ($attributes && $attributes[$attribute_name]) {
			return (string)$attributes[$attribute_name];
		} else
			return '';
	}
	public static function to_xml(\SimpleXMLElement $object, array $data) {
		 foreach ($data as $key => $value) {
			  if (is_array($value)) {
					$new_object = $object->addChild($key);
					XMLHelper::to_xml($new_object, $value);
			  } else {
					// if the key is an integer, it needs text with it to actually work.
					if ($key != 0 && $key == (int) $key) {
						 $key = "key_$key";
					}

					$object->addChild($key, $value);
			  }
		 }
	}
	public static function array_to_xml(array $arr, \SimpleXMLElement $xml) {
        foreach ($arr as $k => $v) {

            $attrArr = array();
            $kArray = explode(' ',$k);
            $tag = array_shift($kArray);

            if (count($kArray) > 0) {
                foreach($kArray as $attrValue) {
                    $attrArr[] = explode('=',$attrValue);
                }
            }

            if (is_array($v)) {
                if (is_numeric($k)) {
                    array_to_xml($v, $xml);
                } else {
                    $child = $xml->addChild($tag);
                    if (isset($attrArr)) {
                        foreach($attrArr as $attrArrV) {
                            $child->addAttribute($attrArrV[0],$attrArrV[1]);
                        }
                    }
                    XMLHelper::array_to_xml($v, $child);
                }
            } else {
                $child = $xml->addChild($tag, $v);
                if (isset($attrArr)) {
                    foreach($attrArr as $attrArrV) {
                        $child->addAttribute($attrArrV[0],$attrArrV[1]);
                    }
                }
            }
        }

        return $xml;
    }
}