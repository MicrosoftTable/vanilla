<?php
namespace Vanilla;


final class Inst {
    public $cls;
    public $ref;
    public $name;
    public $props = [];
    public $children = [];
    public $parent = null;

    function __construct($cls, $ref) {
        $this->cls = $cls;
        $this->ref = $ref;
        $this->name = $cls;
    }

}
