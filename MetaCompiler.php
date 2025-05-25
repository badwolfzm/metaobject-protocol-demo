<?php
/**
 * MetaCompiler.php
 *
 * Universal Meta-Object and DSL Engine for PHP.
 *
 * This file implements a minimal meta-object protocol and a domain-specific language (DSL) runtime.
 * It enables defining, introspecting, and executing meta-objects and APIs, supporting full round-trip
 * serialization and reconstruction between client and server.
 *
 * (c) 2024 Shahaf Zemah — Licensed under the MIT License
 * https://github.com/<your-username>/<your-repo-name>
 */
error_reporting(E_ALL);
ini_set("display_errors", 1);

// --- MetaVar ---
class MetaVar {
    public string $name;
    public $value;
    public string $type;

    public function __construct(string $name, $value, string $type = "mixed") {
        $this->name = $name;
        $this->value = $value;
        $this->type = $type;
    }

    public function __toString() { return (string) $this->value; }
    public function get() { return $this->value; }
    public function set($val) { $this->value = $val; }
    public function describe(): array {
        return [
            'name' => $this->name,
            'value' => ($this->value instanceof MetaObject) ? $this->value->describe() : $this->value,
            'type' => $this->type
        ];
    }
}

// --- MetaObject ---
class MetaObject {
    public $id;
    public $type;
    public $value;
    public $methods = [];
    public $relations = [];
    public $tags = [];

    public function __construct($id, $type = "generic", $value = null) {
        $this->id = $id;
        $this->type = $type;
        $this->value = $value;
    }

    public function tag(...$tags) {
        foreach ($tags as $tag) {
            if (!in_array($tag, $this->tags)) $this->tags[] = $tag;
        }
    }

    public function addMethod($name, $method) {
        $this->methods[$name] = $method;
    }

    public function call($name, ...$args) {
        $fn = $this->methods[$name] ?? null;
        if (is_callable($fn)) {
            if ($name === "execute") {
                return [
                    "result" => call_user_func_array($fn, $args),
                    "vars" => array_map(fn($arg) => $arg instanceof MetaVar ? $arg->describe() : $arg, $args)
                ];
            }
            return call_user_func_array($fn, $args);
        } elseif ($fn instanceof MetaObject && isset($fn->methods["execute"])) {
            return $fn->call("execute", ...$args);
        }
        throw new Exception("Method '$name' not callable or missing in '{$this->id}'");
    }

    public function relate($type, $object) {
        $this->relations[$type][] = $object;
    }
    // MetaObject: get a field value by name (assumes relation type 'field')
    public function get($key) {
        foreach ($this->relations["field"] ?? [] as $field) {
            if ($field instanceof MetaVar && $field->name === $key) {
                return $field->get();
            }
        }
        return null;
    }
    public function bind($other) {
        foreach ($other->methods as $name => $fn) {
            $this->methods[$name] = $fn;
        }
        $this->tag(...$other->tags);
        return $this;
    }
    public function describe() {
        $rel = [];
        foreach ($this->relations as $type => $list) {
            // KEY FIX: Emit full MetaVar/MetaObject description for each relation (especially for "field")
            $rel[$type] = array_map(function($o) {
                if (method_exists($o, "describe")) return $o->describe();
                return $o;
            }, $list);
        }
        return [
            "id" => $this->id,
            "type" => $this->type,
            "tags" => $this->tags,
            "methods" => array_keys($this->methods),
            "relations" => $rel,
            "value" => $this->value
        ];
    }
    public function __toString() {
        return json_encode($this->describe(), JSON_PRETTY_PRINT);
    }
}

// --- MetaCompiler ---
class MetaCompiler {
    public $objects = [];
    public $debug = false;

    public function setDebug($bool) { $this->debug = (bool)$bool; }
    public function addObject(MetaObject $obj) { $this->objects[$obj->id] = $obj; }
    public function describeObject(string $id): array {
        return isset($this->objects[$id]) ? $this->objects[$id]->describe() : [];
    }

    public function compile($code) {
        $lines = $this->splitLinesAndBlocks($code);
        foreach ($lines as $line) {
            $line = trim($line);
            if (!$line) continue;
            if (preg_match('/^debug (on|off)/i', $line, $m)) {
                $this->setDebug(strtolower($m[1]) === 'on');
                if ($this->debug) echo "[SYSTEM] Debug mode ON\n";
                continue;
            }
            if ($this->debug) echo "\n[DSL] $line\n";
            if (str_starts_with($line, "let")) $this->handleDefine($line);
            elseif (str_starts_with($line, "tag")) $this->handleTag($line);
        }
        return null;
    }

    public function exportApiRoutes() {
        $routes = [];
        foreach ($this->objects as $id => $obj) {
            if (in_array("api", $obj->tags)) {
                $pathTag = array_filter($obj->tags, fn($t) => str_starts_with($t, "path:"));
                if (!$pathTag) continue;
                $path = explode(":", reset($pathTag), 2)[1] ?? null;
                $method = in_array("post", $obj->tags) ? "POST" : "GET";
                $routes[] = ["path" => $path, "method" => $method, "handler" => $obj];
            }
        }
        return $routes;
    }

    // === Recursive MetaObject Builder ===
    public function buildMetaObject($name, $val, $sourceType = 'get') {
        $obj = new MetaObject($name, "abstract-object");
        foreach ($val as $k => $v) {
            if (is_array($v)) {
                $obj->relate("field", new MetaVar($k, $this->buildMetaObject($k, $v, $sourceType), $sourceType));
            } else {
                $obj->relate("field", new MetaVar($k, $v, $sourceType));
            }
        }
        return $obj;
    }

    // === Main argument builder, supports recursion ===
    public function getOrderedArgsAsObjects($function, $source, $sourceType = 'get') {
        $ref = new ReflectionFunction($function);
        $args = [];
        foreach ($ref->getParameters() as $param) {
            $name = $param->getName();
            $val = $source[$name] ?? null;
            if (is_array($val)) {
                $args[] = new MetaVar($name, $this->buildMetaObject($name, $val, $sourceType), $sourceType);
            } else {
                $args[] = new MetaVar($name, $val, $sourceType);
            }
        }
        return $args;
    }

    // ... DSL parsing helpers (as in your code) ...
    private function arrowToClosure($expr) {
        if (!preg_match('/^\(([^)]*)\)\s*=>\s*(.+)$/s', trim($expr), $m)) return false;
        $params_raw = trim($m[1]);
        $params_arr = array_filter(array_map('trim', explode(',', $params_raw)));
        $params_php = implode(', ', array_map(fn($p) => '$' . $p, $params_arr));
        $body = trim($m[2]);
        foreach ($params_arr as $p) {
            $body = preg_replace_callback(
                '/(["\'])((?:\\\\\1|.)*?)\1|(?<![$a-zA-Z0-9_])' . preg_quote($p, '/') . '(?=\b)/',
                function ($matches) use ($p) {
                    if (isset($matches[2])) return $matches[0];
                    return '$' . $p;
                },
                $body
            );
        }
        if (substr($body, 0, 1) === '{') {
            $body = trim($body, "{} \n\t");
        } else {
            $body = "return $body;";
        }
        $fn_code = "function($params_php) { $body; }";
        if ($this->debug) echo "\n[DEBUG EVAL]: $fn_code\n";
        return $fn_code;
    }

    private function splitLinesAndBlocks($code) {
        $lines = preg_split('/\n|\r\n?/', $code);
        $out = [];
        $block = "";
        $in_block = false;
        $braces = 0;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (preg_match('/^(let|connector)\s+\w+.*=>\s*{/', $trimmed)) {
                $in_block = true;
                $block = $trimmed;
                $braces = substr_count($trimmed, '{') - substr_count($trimmed, '}');
                if ($braces === 0) {
                    $out[] = rtrim($block, ';');
                    $block = "";
                    $in_block = false;
                }
                continue;
            }

            if ($in_block) {
                $block .= "\n" . $line;
                $braces += substr_count($line, '{') - substr_count($line, '}');
                if ($braces <= 0) {
                    $out[] = rtrim($block, ';');
                    $block = "";
                    $in_block = false;
                }
                continue;
            }

            if (trim($line) !== "") $out[] = trim($line);
        }

        if ($in_block && $block) {
            echo "[ERROR] Unclosed '{' in block.\n";
            $out[] = rtrim($block, ';');
        }

        $final = [];
        foreach ($out as $stmt) {
            if (preg_match('/^(let|connector)\s/', $stmt)) {
                $final[] = $stmt;
            } else {
                foreach (preg_split('/;\s*/', $stmt) as $s) {
                    if (trim($s)) $final[] = trim($s);
                }
            }
        }
        return $final;
    }

    private function handleDefine($line) {
        preg_match('/let (\w+) = (.+)/s', $line, $m);
        $id = $m[1];
        $expr = trim($m[2]);
        try {
            if (strpos($expr, "=>") !== false) {
                $expr2 = $this->arrowToClosure($expr);
                if (!$expr2) throw new Exception("Invalid arrow function in '$id'");
                $fn = eval("return $expr2;");
                $obj = new MetaObject($id, "function");
                $obj->addMethod("execute", $fn);
                $obj->tag("callable");
            } else {
                $val = eval("return {$expr};");
                $obj = new MetaObject($id, "value", $val);
                $obj->tag("value");
            }
            $this->addObject($obj);
            if ($this->debug) echo "[OK] Object '$id' created\n";
        } catch (Throwable $e) {
            echo "[ERROR] In define '$id': " . $e->getMessage() . "\n";
        }
    }

    private function handleTag($line) {
        preg_match('/tag (\w+) as (.+)/', $line, $m);
        $id = $m[1];
        $tags = array_map('trim', explode(',', $m[2]));
        if (isset($this->objects[$id])) {
            $this->objects[$id]->tag(...$tags);
            if ($this->debug) echo "[OK] Tagged '$id' as " . implode(", ", $tags) . "\n";
        } else {
            echo "[ERROR] Tried to tag missing object '$id'\n";
        }
    }
}
?>
