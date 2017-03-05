<?php
trait UniqueInstance
{
	// ::registerInstance(self)
	public static function registerInstance($instance)
	{
		return $GLOBALS[__CLASS__ . 'INSTANCE'] = $instance;
	}
	// ::getInstance() : new (self);
	public static function getInstance()
	{
		if(isset($GLOBALS[__CLASS__ . 'INSTANCE']))
			return $GLOBALS[__CLASS__ . 'INSTANCE'];
		else 
			return self::registerInstance(new self());
	}

}

trait InlineConstructor
{
	// class::__New([..]) ==> must return new class([..])
	public static function __New()
	{
		$args = func_get_args();
		if(count($args) == 0)
			return new self();
		else 
		{
			$r = new ReflectionClass(__CLASS__);
			return $r->newInstanceArgs($args);
		}
	}
}
