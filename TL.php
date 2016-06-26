<?php
set_include_path(get_include_path() . PATH_SEPARATOR . dirname(__FILE__) . DIRECTORY_SEPARATOR . 'libpy2php');
require_once ('libpy2php.php');
$__author__ = 'agrigoryev';
require_once ('os.php');
class TlConstructor {
    function __construct($json_dict) {
        $this->id = pyjslib_int($json_dict['id']);
        $this->type = $json_dict['type'];
        $this->predicate = $json_dict['predicate'];
        $this->params = [];
        foreach ($json_dict['params'] as $param) {
            if (($param['type'] == 'Vector<long>')) {
                $param['type'] = 'Vector t';
                $param['subtype'] = 'long';
            } else if (($param['type'] == 'vector<%Message>')) {
                $param['type'] = 'vector';
                $param['subtype'] = 'message';
            } else if (($param['type'] == 'vector<future_salt>')) {
                $param['type'] = 'vector';
                $param['subtype'] = 'future_salt';
            } else {
                $param['subtype'] = null;
            }
            $this->params[] = $param;
        }
    }
}
class TlMethod {
    function __construct($json_dict) {
        $this->id = pyjslib_int($json_dict['id']);
        $this->type = $json_dict['type'];
        $this->method = $json_dict['method'];
        $this->params = $json_dict['params'];
    }
}
class TLObject {
    function __construct($tl_elem) {
        $this->name = $tl_elem->predicate;
    }
}
class TL {
    function __construct($filename) {
        $TL_dict = json_decode(file_get_contents($filename), true);
        $this->constructors = $TL_dict['constructors'];
        $this->constructor_id = [];
        $this->constructor_type = [];
        foreach ($this->constructors as $elem) {
            $z = new TlConstructor($elem);
            $this->constructor_id[$z->id] = $z;
            $this->constructor_type[$z->predicate] = $z;
        }
        $this->methods = $TL_dict['methods'];
        $this->method_id = [];
        $this->method_name = [];
        foreach ($this->methods as $elem) {
            $z = new TlMethod($elem);
            $this->method_id[$z->id] = $z;
            $this->method_name[$z->method] = $z;
        }
    }
}
$tl = new TL(__DIR__ . '/TL_schema.JSON');
function serialize_obj($type_, $kwargs) {
    $bytes_io = io::BytesIO();
    try {
        $tl_constructor = $tl->constructor_type[$type_];
    }
    catch(KeyError $e) {
        throw new $Exception(sprintf('Could not extract type: %s', $type_));
    }
    $bytes_io->write(struct::pack('<i', $tl_constructor->id));
    foreach ($tl_constructor->params as $arg) {
        py2php_kwargs_function_call('serialize_param', [$bytes_io], ["type_" => $arg['type'], "value" => $kwargs[$arg['name']]]);
    }
    return $bytes_io->getvalue();
}
function serialize_method($type_, $kwargs) {
    $bytes_io = io::BytesIO();
    try {
        $tl_method = $tl->method_name[$type_];
    }
    catch(KeyError $e) {
        throw new $Exception(sprintf('Could not extract type: %s', $type_));
    }
    $bytes_io->write(struct::pack('<i', $tl_method->id));
    foreach ($tl_method->params as $arg) {
        py2php_kwargs_function_call('serialize_param', [$bytes_io], ["type_" => $arg['type'], "value" => $kwargs[$arg['name']]]);
    }
    return $bytes_io->getvalue();
}
function serialize_param($bytes_io, $type_, $value) {
    if (($type_ == 'int')) {
        assert(isinstance($value, Number));
        assert(($value->bit_length() <= 32));
        $bytes_io->write(struct::pack('<i', $value));
    } else if (($type_ == 'long')) {
        assert(isinstance($value, Number));
        $bytes_io->write(struct::pack('<q', $value));
    } else if (in_array($type_, ['int128', 'int256'])) {
        assert(isinstance($value, $bytes));
        $bytes_io->write($value);
    } else if (($type_ == 'string') || 'bytes') {
        $l = count($value);
        if (($l < 254)) {
            $bytes_io->write(struct::pack('<b', $l));
            $bytes_io->write($value);
            $bytes_io->write((' ' * ((-$l - 1) % 4)));
        } else {
            $bytes_io->write('þ');
            $bytes_io->write(array_slice(struct::pack('<i', $l), null, 3));
            $bytes_io->write($value);
            $bytes_io->write((' ' * (-$l % 4)));
        }
    }
}
/**
 * :type bytes_io: io.BytesIO object
 */
function deserialize($bytes_io, $type_ = null, $subtype = null) {
    assert(isinstance($bytes_io, io::BytesIO));
    if (($type_ == 'int')) {
        $x = struct::unpack('<i', $bytes_io->read(4)) [0];
    } else if (($type_ == '#')) {
        $x = struct::unpack('<I', $bytes_io->read(4)) [0];
    } else if (($type_ == 'long')) {
        $x = struct::unpack('<q', $bytes_io->read(8)) [0];
    } else if (($type_ == 'double')) {
        $x = struct::unpack('<d', $bytes_io->read(8)) [0];
    } else if (($type_ == 'int128')) {
        $x = $bytes_io->read(16);
    } else if (($type_ == 'int256')) {
        $x = $bytes_io->read(32);
    } else if (($type_ == 'string') || ($type_ == 'bytes')) {
        $l = struct::unpack('<B', $bytes_io->read(1)) [0];
        assert(($l <= 254));
        if (($l == 254)) {
            $long_len = struct::unpack('<I', $bytes_io->read(3) . ' ') [0];
            $x = $bytes_io->read($long_len);
            $bytes_io->read((-$long_len % 4));
        } else {
            $x = $bytes_io->read($l);
            $bytes_io->read((-($l + 1) % 4));
        }
        assert(isinstance($x, $bytes));
    } else if (($type_ == 'vector')) {
        assert(($subtype != null));
        $count = struct::unpack('<l', $bytes_io->read(4)) [0];
        $x = [];
        foreach( pyjslib_range($count) as $i ) {
           $x[] = (py2php_kwargs_function_call('$deserialize', [$bytes_io], ["type_" => $subtype]));
        }
    } else {
        try {
            $tl_elem = $tl->constructor_type[$type_];
        }
        catch(KeyError $e) {
            $i = struct::unpack('<i', $bytes_io->read(4)) [0];
            try {
                $tl_elem = $tl->constructor_id[$i];
            }
            catch(KeyError $e) {
                throw new $Exception(sprintf('Could not extract type: %s', $type_));
            }
        }
        $base_boxed_types = ['Vector t', 'Int', 'Long', 'Double', 'String', 'Int128', 'Int256'];
        if (in_array($tl_elem->type, $base_boxed_types)) {
            $x = py2php_kwargs_function_call('deserialize', [$bytes_io], ["type_" => $tl_elem->predicate, "subtype" => $subtype]);
        } else {
            $x = new TLObject($tl_elem);
            foreach ($tl_elem->params as $arg) {
                $x[$arg['name']] = py2php_kwargs_function_call('deserialize', [$bytes_io], ["type_" => $arg['type'], "subtype" => $arg['subtype']]);
            }
        }
    }
    return $x;
}
