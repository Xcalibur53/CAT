<?php

/*
 * ******************************************************************************
 * Copyright 2011-2017 DANTE Ltd. and GÉANT on behalf of the GN3, GN3+, GN4-1 
 * and GN4-2 consortia
 *
 * License: see the web/copyright.php file in the file structure
 * ******************************************************************************
 */

namespace web\lib\common;

/**
 * performs validation of user inputs
 */
class InputValidation {

    /**
     * returns a simple HTML <p> element with basic explanations about what was
     * wrong with the input
     * 
     * @param string $customtext explanation provided by the validator function
     * @return string
     */
    private function inputValidationError($customtext) {
        return "<p>" . _("Input validation error: ") . $customtext . "</p>";
    }

    /**
     * Is this a known Federation? Optionally, also check if the authenticated
     * user is a federation admin of that federation
     * @param string $input the ISO code of the federation
     * @param string|NULL $owner the authenticated username, optional
     * @return \core\Federation
     * @throws Exception
     */
    public function Federation($input, $owner = NULL) {
        
        $cat = new \core\CAT();
        $fedIdentifiers = array_keys($cat->knownFederations);
        if  (!in_array(strtoupper($input), $fedIdentifiers)) {
            throw new Exception($this->inputValidationError("This federation does not exist!"));
        }
        // totally circular, but this hopefully *finally* make Scrutinizer happier
        $correctIndex = array_search(strtoupper($input), $fedIdentifiers);
        $postFed = $fedIdentifiers[$correctIndex];
        
        $temp = new \core\Federation($postFed);
        if ($owner === NULL) {
            return $temp;
        }

        foreach ($temp->listFederationAdmins() as $oneowner) {
            if ($oneowner == $owner) {
                return $temp;
            }
        }
        throw new Exception($this->inputValidationError("User is not federation administrator!"));
    }

    /**
     * Is this a known IdP? Optionally, also check if the authenticated
     * user is an admin of that IdP
     * @param int $input the numeric ID of the IdP in the system
     * @param string|NULL $owner the authenticated username, optional
     * @return \core\IdP
     * @throws Exception
     */
    public function IdP($input, $owner = NULL) {
        if (!is_numeric($input)) {
            throw new Exception($this->inputValidationError("Value for IdP is not an integer!"));
        }

        $temp = new \core\IdP($input); // constructor throws an exception if NX, game over

        if ($owner !== NULL) { // check if the authenticated user is allowed to see this institution
            foreach ($temp->owner() as $oneowner) {
                if ($oneowner['ID'] == $owner) {
                    return $temp;
                }
            }
            throw new Exception($this->inputValidationError("This IdP identifier is not accessible!"));
        }
        return $temp;
    }

    /**
     * Checks if the input refers to a known Profile. Optionally also takes an
     * IdP identifier and then checks if the Profile belongs to the refernced 
     * IdP
     * 
     * @param int $input the numeric ID of the Profile in the system
     * @param int|NULL $idpIdentifier the numeric ID of the IdP in the system, optional
     * @return \core\AbstractProfile
     * @throws Exception
     */
    public function Profile($input, $idpIdentifier = NULL) {
        if (!is_numeric($input)) {
            throw new Exception($this->inputValidationError("Value for profile is not an integer!"));
        }

        $temp = \core\ProfileFactory::instantiate($input); // constructor throws an exception if NX, game over

        if ($idpIdentifier !== NULL && $temp->institution != $idpIdentifier) {
            throw new Exception($this->inputValidationError("The profile does not belong to the IdP!"));
        }
        return $temp;
    }

    /**
     * Checks if this is a device known to the system
     * @param string $input the name of the device (index in the Devices.php array)
     * @return string returns the same string on success, throws an Exception on failure
     * @throws Exception
     */
    public function Device($input) {
        $devicelist = \devices\Devices::listDevices();
        $keyArray = array_keys($devicelist);
        if (!isset($devicelist[$input])) {
            throw new Exception($this->inputValidationError("This device does not exist!"));
        }
        $correctIndex = array_search($input, $keyArray);
        return $keyArray[$correctIndex];
    }

    /**
     * 
     * @param string $input a string to be made SQL-safe
     * @param boolean $allowWhitespace whether some whitespace (e.g. newlines should be preserved (true) or redacted (false)
     * @return string the massaged string
     */
    public function string($input, $allowWhitespace = FALSE) {
    // always chop out invalid characters, and surrounding whitespace
    $retvalStep1 =  trim(iconv("UTF-8", "UTF-8//TRANSLIT", $input));
    // if some funny person wants to inject markup tags, remove them
    $retval = filter_var($retvalStep1, FILTER_SANITIZE_STRING, ["flags" => FILTER_FLAG_NO_ENCODE_QUOTES]);
    // unless explicitly wanted, take away intermediate disturbing whitespace
    // a simple "space" is NOT disturbing :-)
    if ($allowWhitespace === FALSE) {
        $afterWhitespace = preg_replace('/(\0|\r|\x0b|\t|\n)/', '', $retval);
    } else {
        // even if we allow whitespace, not pathological ones!
        $afterWhitespace = preg_replace('/(\0|\r|\x0b)/', '', $retval);
    }
    if (is_array($afterWhitespace)) {
        throw new Exception("This function has to be given a string and returns a string. preg_replace has generated an array instead!");
    }
    return $afterWhitespace;
}

/**
 * Is this an integer, or a string that represents an integer?
 * @param string|int $input
 * @return false|string|int returns the input, or FALSE if it is not an integer-like value
 */
public function integer($input) {
    if (is_numeric($input)) {
        return $input;
    }
    return FALSE;
}

/**
 * Checks if the input is the hex representation of a Consortium OI (i.e. three
 * or five bytes)
 * @param string $input
 * @return false|string returns the input, or FALSE on validation failure
 */
public function consortium_oi($input) {
    $shallow = $this->string($input);
    if (strlen($shallow) != 6 && strlen($shallow) != 10) {
        return FALSE;
    }
    if (!preg_match("/^[a-fA-F0-9]+$/", $shallow)) {
        return FALSE;
    }
    return $shallow;
}

/**
 * Is the input an NAI realm? Throws HTML error and returns FALSE if not.
 * @param string $input the input to check
 * @return false|string returns the realm, or FALSE if it was malformed
 */
public function realm($input) {
    // basic string checks
    $check = $this->string($input);
    // bark on invalid constructs
    if (preg_match("/@/", $check) == 1) {
        echo $this->inputValidationError(_("Realm contains an @ sign!"));
        return FALSE;
    }
    if (preg_match("/^\./", $check) == 1) {
        echo $this->inputValidationError(_("Realm begins with a . (dot)!"));
        return FALSE;
    }
    if (preg_match("/\.$/", $check) == 1) {
        echo $this->inputValidationError(_("Realm ends with a . (dot)!"));
        return FALSE;
    }
    if (preg_match("/\./", $check) == 0) {
        echo $this->inputValidationError(_("Realm does not contain at least one . (dot)!"));
        return FALSE;
    }
    if (preg_match("/ /", $check) == 1) {
        echo $this->inputValidationError(_("Realm contains spaces!"));
        return FALSE;
    }
    if (strlen($input) == 0) {
        echo $this->inputValidationError(_("Realm is empty!"));
        return FALSE;
    }
    // none of the special HTML entities should be here. In case someone wants
    // to mount a CSS attack by providing something that matches the realm constructs
    // below but has interesting stuff between, mangle the input so that these
    // characters do not do any harm.
    return htmlentities($check, ENT_QUOTES);
}

/**
 * could this be a valid username? Only checks correct form, not if the user
 * actually exists in the system.
 * @param string $input
 * @return string echoes back the input string, or throws an Exception if bogus
 * @throws Exception
 */
public function User($input) {
    $retval = $input;
    if ($input != "" && !ctype_print($input)) {
        throw new Exception($this->inputValidationError("The user identifier is not an ASCII string!"));
    }
    return $retval;
}

/**
 * could this be a valid token? Only checks correct form, not if the token
 * actually exists in the system.
 * @param string $input
 * @return string echoes back the input string, or throws an Exception if bogus
 * @throws Exception
 */
public function token($input) {
    $retval = $input;
    if ($input != "" && preg_match('/[^0-9a-fA-F]/', $input) != 0) {
        throw new Exception($this->inputValidationError("Token is not a hexadecimal string!"));
    }
    return $retval;
}

/**
 * 
 * @param string $input a numeric value in range of a geo coordinate [-180;180]
 * @return string returns back the input if all is good; throws an Exception if out of bounds or not numeric
 * @throws Exception
 */
public function coordinate($input) {
    $oldlocale = setlocale(LC_NUMERIC, 0);
    setlocale(LC_NUMERIC, "en_GB");
    if (!is_numeric($input)) {
        throw new Exception($this->inputValidationError("Coordinate is not a numeric value!"));
    }
    setlocale(LC_NUMERIC, $oldlocale);
    // lat and lon are always in the range of [-180;+180]
    if ($input < -180 || $input > 180) {
        throw new Exception($this->inputValidationError("Coordinate is out of bounds. Which planet are you from?"));
    }
    return $input;
}

/**
 * 
 * @param string $input the string to be checked: is this a serialised array with lat/lon keys in a valid number range?
 * @return string returns $input if checks have passed; throws an Exception if something's wrong
 * @throws Exception
 */
public function coordSerialized($input) {
    $tentative = unserialize($input, ["allowed_classes" => false]);
    if (is_array($tentative)) {
        if (isset($tentative['lon']) && isset($tentative['lat']) && $this->coordinate($tentative['lon']) && $this->coordinate($tentative['lat'])) {
            return $input;
        }
    }
    throw new Exception($this->inputValidationError(_("Wrong coordinate encoding!")));
}

/**
 * This checks the state of a HTML GET/POST "boolean" (if not checked, no value
 * is submitted at all; if checked, has the word "on". Anything else is a big
 * error
 * @param string $input the string to test
 * @return string echoes back the input if good, throws an Exception otherwise
 * @throws Exception
 */
public function boolean($input) {
    if ($input != "on") {
        throw new Exception($this->inputValidationError("Unknown state of boolean option!"));
    }
    return $input;
}

const TABLEMAPPING = [
    "IdP" => "institution_option",
    "Profile" => "profile_option",
    "FED" => "federation_option",
];
/**
 * Is this a valid database reference? Has the form <tablename>-<rowID> and there
 * needs to be actual data at that place
 * @param string $input the reference to check
 * @return false|array the reference split up into "table" and "rowindex", or FALSE
 */
public function databaseReference($input) {
    $pregMatches = [];
    if (preg_match("/^ROWID-(IdP|Profile|FED)-([0-9]+)$/", $input, $pregMatches) === FALSE) {
        return FALSE;
    }
    return ["table" => self::TABLEMAPPING[$pregMatches[1]], "rowindex" => $pregMatches[2]];
}

/**
 * is this a valid hostname?
 * @param string $input
 * @return false|string echoes the hostname, or FALSE if bogus
 */
public function hostname($input) {
    // is it a valid IP address (IPv4 or IPv6)?
    if (filter_var($input, FILTER_VALIDATE_IP)) {
        return $input;
    }
    if ($this->email("stefan@" . $input) !== FALSE) {
        return $input;
    }
    return FALSE;
}

/**
 * is this a valid email address?
 * @param string $input
 * @return false|string echoes the mail address, or FALSE if bogus
 */
public function email($input) {
    
    if (filter_var($input, FILTER_VALIDATE_EMAIL)) {
        return $input;
    }
    // if we get here, it's bogus
    return FALSE;
}

}