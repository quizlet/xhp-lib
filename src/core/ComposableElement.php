<?hh
/*
 *  Copyright (c) 2015, Facebook, Inc.
 *  All rights reserved.
 *
 *  This source code is licensed under the BSD-style license found in the
 *  LICENSE file in the root directory of this source tree. An additional grant
 *  of patent rights can be found in the PATENTS file in the same directory.
 *
 */

abstract class :x:composable-element extends :xhp {
  private Map<string, mixed> $attributes = Map {};
  private Vector<XHPChild> $children = Vector {};
  private Map<string, mixed> $context = Map {};

  private static $specialAttributes = ImmSet {
    'data',
    'aria',
  };

  // Helper to put all the UNSAFE in one place until facebook/hhvm#4830 is
  // addressed
  protected static async function __xhpAsyncRender(
    XHPAwaitable $child,
  ): Awaitable<XHPRoot> {
    // UNSAFE
    return await $child->asyncRender();
  }

  // Private constants indicating the declared types of attributes
  const int TYPE_STRING   = 1;
  const int TYPE_BOOL     = 2;
  const int TYPE_INTEGER  = 3;
  const int TYPE_ARRAY    = 4;
  const int TYPE_OBJECT   = 5;
  const int TYPE_VAR      = 6;
  const int TYPE_ENUM     = 7;
  const int TYPE_FLOAT    = 8;

  protected function init(): void {}

  /**
   * A new :x:composable-element is instantiated for every literal tag
   * expression in the script.
   *
   * The following code:
   * $foo = <foo attr="val">bar</foo>;
   *
   * will execute something like:
   * $foo = new xhp_foo(array('attr' => 'val'), array('bar'));
   *
   * @param $attributes    map of attributes to values
   * @param $children      list of children
   */
  final public function __construct(KeyedTraversable<string, mixed> $attributes,
                                    Traversable<XHPChild> $children) {
    parent::__construct($attributes, $children);
    foreach ($children as $child) {
      $this->appendChild($child);
    }
    $this->setAttributes($attributes);
    if (:xhp::$ENABLE_VALIDATION) {
      // There is some cost to having defaulted unused arguments on a function
      // so we leave these out and get them with func_get_args().
      $args = func_get_args();
      if (isset($args[2])) {
        $this->source = "$args[2]:$args[3]";
      } else {
        $this->source =
          'You have ENABLE_VALIDATION on, but debug information is not being ' .
          'passed to XHP objects correctly. Ensure xhp.include_debug is on ' .
          'in your PHP configuration. Without this option enabled, ' .
          'validation errors will be painful to debug at best.';
      }
    }
    $this->init();
  }

  /**
   * Adds a child to the end of this node. If you give an array to this method
   * then it will behave like a DocumentFragment.
   *
   * @param $child     single child or array of children
   */
  final public function appendChild(mixed $child): this {
    if ($child instanceof Traversable) {
      foreach ($child as $c) {
        $this->appendChild($c);
      }
    } else if ($child instanceof :x:frag) {
      $this->children->addAll($child->getChildren());
    } else if ($child !== null) {
      assert($child instanceof XHPChild);
      $this->children->add($child);
    }
    return $this;
  }

  /**
   * Adds a child to the beginning of this node. If you give an array to this
   * method then it will behave like a DocumentFragment.
   *
   * @param $child     single child or array of children
   */
  final public function prependChild(mixed $child): this {
    // There's no prepend to a Vector, so reverse, append, and reverse agains
    $this->children->reverse();
    $this->appendChild($child);
    $this->children->reverse();
    return $this;
  }

  /**
   * Replaces all children in this node. You may pass a single array or
   * multiple parameters.
   *
   * @param $children  Single child or array of children
   */
  final public function replaceChildren(...): this {
    // This function has been micro-optimized
    $args = func_get_args();
    $new_children = Vector {};
    foreach ($args as $xhp) {
      if ($xhp) {
        if ($xhp instanceof :x:frag) {
          foreach ($xhp->children as $child) {
            $new_children->add($child);
          }
        } else if (!($xhp instanceof Traversable)) {
          $new_children->add($xhp);
        } else {
          foreach ($xhp as $element) {
            if ($element instanceof :x:frag) {
              foreach ($element->children as $child) {
                $new_children->add($child);
              }
            } else if ($element !== null) {
              $new_children->add($element);
            }
          }
        }
      }
    }
    $this->children = $new_children;
    return $this;
  }

  /**
   * Fetches all direct children of this element that match a particular tag
   * name or category (or all children if none is given)
   *
   * @param $selector   tag name or category (optional)
   * @return array
   */
  final public function getChildren(
    ?string $selector = null,
  ): Vector<XHPChild> {
    if ($selector) {
      $children = Vector {};
      if ($selector[0] == '%') {
        $selector = substr($selector, 1);
        foreach ($this->children as $child) {
          if ($child instanceof :xhp && $child->categoryOf($selector)) {
            $children->add($child);
          }
        }
      } else {
        $selector = :xhp::element2class($selector);
        foreach ($this->children as $child) {
          if ($child instanceof $selector) {
            $children->add($child);
          }
        }
      }
    } else {
      $children = new Vector($this->children);
    }
    return $children;
  }


  /**
   * Fetches the first direct child of the element, or the first child that
   * matches the tag if one is given
   *
   * @param $selector   string   tag name or category (optional)
   * @return            element  the first child node (with the given selector),
   *                             false if there are no (matching) children
   */
  final public function getFirstChild(?string $selector = null): ?XHPChild {
    if (!$selector) {
      return $this->children->get(0);
    } else if ($selector[0] == '%') {
      $selector = substr($selector, 1);
      foreach ($this->children as $child) {
        if ($child instanceof :xhp && $child->categoryOf($selector)) {
          return $child;
        }
      }
    } else {
      $selector = :xhp::element2class($selector);
      foreach ($this->children as $child) {
        if ($child instanceof $selector) {
          return $child;
        }
      }
    }
    return null;
  }

  /**
   * Fetches the last direct child of the element, or the last child that
   * matches the tag or category if one is given
   *
   * @param $selector  string   tag name or category (optional)
   * @return           element  the last child node (with the given selector),
   *                            false if there are no (matching) children
   */
  final public function getLastChild(?string $selector = null): ?XHPChild {
    $temp = $this->getChildren($selector);
    if ($temp->count() > 0) {
      $count = $temp->count();
      return $temp->at($count - 1);
    }
    return null;
  }

  /**
   * Returns true if the attribute is a data- or aria- attribute.
   *
   * @param $attr      attribute to fetch
   * @return           bool
   */
  final private static function isAttributeSpecial(string $attr): bool {
    // Must be at least 6 characters, with a '-' in the 5th position
    return
      isset($attr[5])
      && $attr[4] == '-'
      && self::$specialAttributes->contains(substr($attr, 0, 4));
  }

  /**
   * Fetches an attribute from this elements attribute store. If $attr is not
   * defined in the store and is not a data- or aria- attribute an exception
   * will be thrown. An exception will also be thrown if $attr is required and
   * not set.
   *
   * @param $attr      attribute to fetch
   * @return           value
   */
  final public function getAttribute(string $attr) {
    // Return the attribute if it's there
    if ($this->attributes->containsKey($attr)) {
      return $this->attributes->get($attr);
    }

    if (!self::isAttributeSpecial($attr)) {
      // Get the declaration
      $decl = static::__xhpAttributeDeclaration();

      if (!isset($decl[$attr])) {
        throw new XHPAttributeNotSupportedException($this, $attr);
      } else if (!empty($decl[$attr][3])) {
        throw new XHPAttributeRequiredException($this, $attr);
      } else {
        return $decl[$attr][2];
      }
    } else {
      return null;
    }
  }

  final public function getAttributes(): Map<string, mixed> {
    return $this->attributes->toMap();
  }

  /**
   * Sets an attribute in this element's attribute store. If the attribute is
   * not defined in the store and is not a data- or aria- attribute an
   * exception will be thrown. An exception will also be thrown if the
   * attribute value is invalid.
   *
   * @param $attr      attribute to set
   * @param $val       value
   */
  final public function setAttribute(string $attr, mixed $value): this {
    if (!self::isAttributeSpecial($attr)) {
      $value = $this->validateAttributeValue($attr, $value);
    } else {
      $value = (string)$value;
    }
    $this->attributes->set($attr, $value);
    return $this;
  }

  /**
   * Takes an array of key/value pairs and adds each as an attribute.
   *
   * @param $attrs    array of attributes
   */
  final public function setAttributes(
    KeyedTraversable<string, mixed> $attrs,
  ): this {
    foreach ($attrs as $key => $value) {
      $this->setAttribute($key, $value);
    }
    return $this;
  }

  /**
   * Whether the attribute has been explicitly set to a non-null value by the
   * caller (vs. using the default set by "attribute" in the class definition).
   *
   * @param $attr attribute to check
   */
  final public function isAttributeSet(string $attr): bool {
    return $this->attributes->containsKey($attr);
  }

  /**
   * Removes an attribute from this element's attribute store. An exception
   * will be thrown if $attr is not supported.
   *
   * @param $attr      attribute to remove
   * @param $val       value
   */
  final public function removeAttribute(string $attr): this {
    if (!self::isAttributeSpecial($attr)) {
      $value = $this->validateAttributeValue($attr, null);
    }
    $this->attributes->removeKey($attr);
    return $this;
  }

  /**
   * Sets an attribute in this element's attribute store. Always foregoes
   * validation.
   *
   * @param $attr      attribute to set
   * @param $val       value
   */
  final public function forceAttribute(string $attr, mixed $value): this {
    $this->attributes->set($attr, $value);
    return $this;
  }
  /**
   * Returns all contexts currently set.
   *
   * @return array  All contexts
   */
  final public function getAllContexts(): Map<string, mixed> {
    return $this->context->toMap();
  }

  /**
   * Returns a specific context value. Can include a default if not set.
   *
   * @param string $key     The context key
   * @param mixed $default  The value to return if not set (optional)
   * @return mixed          The context value or $default
   */
  final public function getContext(string $key, mixed $default): mixed {
    if ($this->context->containsKey($key)) {
      return $this->context->get($key);
    }
    return $default;
  }

  /**
   * Sets a value that will be automatically passed down through a render chain
   * and can be referenced by children and composed elements. For instance, if
   * a root element sets a context of "admin_mode" = true, then all elements
   * that are rendered as children of that root element will receive this
   * context WHEN RENDERED. The context will not be available before render.
   *
   * @param mixed $key      Either a key, or an array of key/value pairs
   * @param mixed $default  if $key is a string, the value to set
   * @return :xhp           $this
   */
  final public function setContext(string $key, mixed $value): this {
    $this->context->set($key, $value);
    return $this;
  }

  /**
   * Sets a value that will be automatically passed down through a render chain
   * and can be referenced by children and composed elements. For instance, if
   * a root element sets a context of "admin_mode" = true, then all elements
   * that are rendered as children of that root element will receive this
   * context WHEN RENDERED. The context will not be available before render.
   *
   * @param Map $context  A map of key/value pairs
   * @return :xhp         $this
   */
  final public function addContextMap(Map<string, mixed> $context): this {
    $this->context->setAll($context);
    return $this;
  }

  /**
   * Transfers the context but will not overwrite anything. This is done only
   * for rendering because we don't want a parent's context to replace a
   * child's context if they have the same key.
   *
   * @param array $parentContext  The context to transfer
   */
  final protected function __transferContext(
    Map<string, mixed> $parentContext,
  ): void {
    foreach ($parentContext as $key => $value) {
      if (!$this->context->containsKey($key)) {
        $this->context->set($key, $value);
      }
    }
  }

  final protected async function __flushElementChildren(): Awaitable<void> {
    // Flush all :xhp elements to x:primitive's

    foreach ($this->children as $child) {
      if ($child instanceof :x:composable-element) {
        $child->__transferContext($this->context);
      }
    }

    $childWaitHandles = Map{};
    $flushWaitHandles = Vector{};
    do {
      if ($childWaitHandles || $flushWaitHandles) {
        list($awaitedChildren, $_) = await GenArrayWaitHandle::create(array(
          GenMapWaitHandle::create($childWaitHandles),
          GenVectorWaitHandle::create($flushWaitHandles),
        ));
        if ($awaitedChildren) {
          foreach ($awaitedChildren as $i => $awaitedChild) {
            $this->children->set($i, $awaitedChild);
          }
          // Convert <x:frag>s
          $this->replaceChildren(<x:frag>{$this->children}</x:frag>);
          $childWaitHandles = Map{};
        }
        $flushWaitHandles = Vector{};
      }

      $ln = count($this->children);
      for ($i = 0; $i < $ln; ++$i) {
        $child = $this->children->get($i);
        if ($child instanceof :x:element) {
          do {
            assert($child instanceof :x:element);
            if ($child instanceof XHPAwaitable) {
              $child = static::__xhpAsyncRender($child)->getWaitHandle();
            } else {
              $child = $child->render();
            }
            if ($child instanceof WaitHandle) {
              $childWaitHandles[$i] = $child;
            } else if ($child instanceof :x:element) {
              continue;
            } else if ($child instanceof :x:frag) {
              $children = $this->children->toValuesArray();
              array_splice($children, $i, 1, $child->getChildren());
              $this->children = new Vector($children);
              $ln = count($this->children);
              --$i;
            } else if ($child === null) {
              $this->children->removeKey($i);
              $i--;
            } else {
              if ($child instanceof :x:primitive) {
                $flushWaitHandles[] =
                  $child->__flushElementChildren()->getWaitHandle();
              }
              assert($child instanceof XHPChild);
              $this->children[$i] = $child;
            }
          } while ($child instanceof :x:element);
        }
      }
    } while ($childWaitHandles);

    if ($flushWaitHandles) {
      await GenVectorWaitHandle::create($flushWaitHandles);
    }
  }

  /**
   * Defined in elements by the `attribute` keyword. The declaration is simple.
   * There is a keyed array, with each key being an attribute. Each value is
   * an array with 4 elements. The first is the attribute type. The second is
   * meta-data about the attribute. The third is a default value (null for
   * none). And the fourth is whether or not this value is required.
   *
   * Attribute types are suggested by the TYPE_* constants.
   */
  protected static function &__xhpAttributeDeclaration(
  ): array<string, array<int, mixed>> {
    static $_ = array();
    return $_;
  }

  /**
   * Defined in elements by the `category` keyword. This is just a list of all
   * categories an element belongs to. Each category is a key with value 1.
   */
  protected function &__xhpCategoryDeclaration(): array<string, int> {
    static $_ = array();
    return $_;
  }

  /**
   * Defined in elements by the `children` keyword. This returns a pattern of
   * allowed children. The return value is potentially very complicated. The
   * two simplest are 0 and 1 which mean no children and any children,
   * respectively. Otherwise you're dealing with an array which is just the
   * biggest mess you've ever seen.
   */
  protected function &__xhpChildrenDeclaration(): mixed {
    static $_ = 1;
    return $_;
  }

  /**
   * Throws an exception if $val is not a valid value for the attribute $attr
   * on this element.
   */
  final protected function validateAttributeValue<T>(
    string $attr,
    T $val,
  ): mixed {
    $decl = static::__xhpAttributeDeclaration();
    if (!isset($decl[$attr])) {
      throw new XHPAttributeNotSupportedException($this, $attr);
    }
    if ($val === null) {
      return null;
    }
    switch ((int)$decl[$attr][0]) {
      case self::TYPE_STRING:
        if (!is_string($val)) {
          $val = XHPAttributeCoercion::CoerceToString($this, $attr, $val);
        }
        break;

      case self::TYPE_BOOL:
        if (!is_bool($val)) {
          $val = XHPAttributeCoercion::CoerceToBool($this, $attr, $val);
        }
        break;

      case self::TYPE_INTEGER:
        if (!is_int($val)) {
          $val = XHPAttributeCoercion::CoerceToInt($this, $attr, $val);
        }
        break;

      case self::TYPE_FLOAT:
        if (!is_float($val)) {
          $val = XHPAttributeCoercion::CoerceToFloat($this, $attr, $val);
        }
        break;

      case self::TYPE_ARRAY:
        if (!is_array($val)) {
          throw new XHPInvalidAttributeException($this, 'array', $attr, $val);
        }
        break;

      case self::TYPE_OBJECT:
        $class = (string) $decl[$attr][1];
        if ($val instanceof $class) {
          break;
        }
        if (enum_exists($class) && $class::isValid($val)) {
          break;
        }
        throw new XHPInvalidAttributeException(
          $this, $class, $attr, $val
        );
        break;

      // case self::TYPE_VAR: `var` (any type)

      case self::TYPE_ENUM:
        $found = false;
        foreach ((array)$decl[$attr][1] as $enum) {
          if ($enum === $val) {
            $found = true;
            break;
          }
        }
        if (!$found) {
          $enums = 'enum("' . implode('","', (array)$decl[$attr][1]) . '")';
          throw new XHPInvalidAttributeException($this, $enums, $attr, $val);
        }
    }
    return $val;
  }

  /**
   * Validates that this element's children match its children descriptor, and
   * throws an exception if that's not the case.
   */
  final protected function validateChildren(): void {
    $decl = $this->__xhpChildrenDeclaration();
    if ($decl === 1) { // Any children allowed
      return;
    }
    if ($decl === 0) { // No children allowed
      if ($this->children) {
        throw new XHPInvalidChildrenException($this, 0);
      } else {
        return;
      }
    }
    list($ret, $ii) = $this->validateChildrenExpression((array)$decl, 0);
    if (!$ret || $ii < count($this->children)) {
      if (isset($this->children[$ii])
          && $this->children[$ii] instanceof XHPAlwaysValidChild) {
        return;
      }
      throw new XHPInvalidChildrenException($this, $ii);
    }
  }

  final private function validateChildrenExpression(array<int, mixed> $decl,
                                                    int $index): (bool, int) {
    switch ((int)$decl[0]) {
      case 0: // Exactly once -- :fb-thing
        return $this->validateChildrenRule((int)$decl[1], $decl[2], $index);

      case 1: // Zero or more times -- :fb-thing*
        do {
          list($ret, $index) = $this->validateChildrenRule(
            (int)$decl[1],
            $decl[2],
            $index,
          );
        } while ($ret);
        return tuple(true, $index);

      case 2: // Zero or one times -- :fb-thing?
        list($_, $index) = $this->validateChildrenRule(
          (int)$decl[1],
          $decl[2],
          $index,
        );
        return tuple(true, $index);

      case 3: // One or more times -- :fb-thing+
        list($ret, $index) = $this->validateChildrenRule(
          (int)$decl[1],
          $decl[2],
          $index,
        );
        if (!$ret) {
          return tuple(false, $index);
        }
        do {
          list($ret, $index) = $this->validateChildrenRule(
            (int)$decl[1],
            $decl[2],
            $index,
          );
        } while ($ret);
        return tuple(true, $index);

      case 4: // Specific order -- :fb-thing, :fb-other-thing
        $oindex = $index;
        list($ret, $index) = $this->validateChildrenExpression(
          (array)$decl[1],
          $index,
        );
        if ($ret) {
          list($ret, $index) = $this->validateChildrenExpression(
            (array)$decl[2],
            $index,
          );
        }
        if ($ret) {
          return tuple(true, $index);
        }
        return tuple(false, $oindex);

      case 5: // Either or -- :fb-thing | :fb-other-thing
        $oindex = $index;
        list($ret, $index) = $this->validateChildrenExpression(
          (array)$decl[1],
          $index,
        );
        if (!$ret) {
          list($ret, $index) = $this->validateChildrenExpression(
            (array)$decl[2],
            $index,
          );
        }
        if ($ret) {
          return tuple(true, $index);
        }
        return tuple(false, $oindex);
    }
  }

  final private function validateChildrenRule(
    int $type,
    mixed $rule,
    int $index,
  ): (bool, int) {
    switch ($type) {
      case 1: // any element -- any
        if ($this->children->containsKey($index)) {
          return tuple(true, $index + 1);
        }
        return tuple(false, $index);

      case 2: // pcdata -- pcdata
        if ($this->children->containsKey($index) &&
            !($this->children->get($index) instanceof :xhp)) {
          return tuple(true, $index + 1);
        }
        return tuple(false, $index);

      case 3: // specific element -- :fb-thing
        if ($this->children->containsKey($index) &&
            $this->children->get($index) instanceof $rule) {
          return tuple(true, $index + 1);
        }
        return tuple(false, $index);

      case 4: // element category -- %block
        if (!$this->children->containsKey($index) ||
            !($this->children->get($index) instanceof :xhp)) {
          return tuple(false, $index);
        }
        $child = $this->children->get($index);
        assert($child instanceof :xhp);
        $categories = $child->__xhpCategoryDeclaration();
        if (empty($categories[(string)$rule])) {
          return tuple(false, $index);
        }
        return tuple(true, $index + 1);

      case 5: // nested rule - ((:fb-thing, :fb-other-thing)*, :fb:thing-footer)
        return $this->validateChildrenExpression((array)$rule, $index);
    }
  }

  /**
   * Returns the human-readable `children` declaration as seen in this class's
   * source code.
   */
  final public function __getChildrenDeclaration(): string {
    $decl = $this->__xhpChildrenDeclaration();
    if ($decl === 1) {
      return 'any';
    }
    if ($decl === 0) {
      return 'empty';
    }
    return $this->renderChildrenDeclaration((array)$decl);
  }

  final private function renderChildrenDeclaration(array $decl): string {
    switch ($decl[0]) {
      case 0:
        return $this->renderChildrenRule((int)$decl[1], $decl[2]);

      case 1:
        return $this->renderChildrenRule((int)$decl[1], $decl[2]) . '*';

      case 2:
        return $this->renderChildrenRule((int)$decl[1], $decl[2]) . '?';

      case 3:
        return $this->renderChildrenRule((int)$decl[1], $decl[2]) . '+';

      case 4:
        return $this->renderChildrenDeclaration($decl[1]) . ',' .
          $this->renderChildrenDeclaration($decl[2]);

      case 5:
        return $this->renderChildrenDeclaration($decl[1]) . '|' .
          $this->renderChildrenDeclaration($decl[2]);
    }
  }

  final private function renderChildrenRule(int $type, mixed $rule): string {
    switch ($type) {
      case 1:
        return 'any';

      case 2:
        return 'pcdata';

      case 3:
        return ':' . :xhp::class2element((string)$rule);

      case 4:
        return '%' . (string)$rule;

      case 5:
        return '(' . $this->renderChildrenDeclaration((array)$rule) . ')';
    }
  }

  /**
   * Returns a description of the current children in this element. Maybe
   * something like this:
   * <div><span>foo</span>bar</div> ->
   * :span[%inline],pcdata
   */
  final public function __getChildrenDescription(): string {
    $desc = array();
    foreach ($this->children as $child) {
      if ($child instanceof :xhp) {
        $tmp = ':' . :xhp::class2element(get_class($child));
        if ($categories = $child->__xhpCategoryDeclaration()) {
          $tmp .= '[%'. implode(',%', array_keys($categories)) . ']';
        }
        $desc[] = $tmp;
      } else {
        $desc[] = 'pcdata';
      }
    }
    return implode(',', $desc);
  }

  final public function categoryOf(string $c): bool {
    $categories = $this->__xhpCategoryDeclaration();
    if (isset($categories[$c])) {
      return true;
    }
    // XHP parses the category string
    $c = str_replace(array(':', '-'), array('__', '_'), $c);
    return isset($categories[$c]);
  }
}
