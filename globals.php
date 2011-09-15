<?php
#
# Place into this file all functions that must exist in the global scope.
# Good examples of this include template functions. Bad examples include
# anything that could otherwise be scoped to your plugin, e.g., action
# and filter hooks.
#
# Also, if you want to make your global functionality pluggable
# (overridable by the user's functions.php or by other plugins), make sure
# to wrap your functions in a call to function_exists('your_function_name').
#
# @see http://php.net/manual/en/function.function-exists.php
#

if (!function_exists('get_the_pointer')):

function get_the_pointer() {
  global $post;
  if (!$post || !$post->ID) {
    return null;
  } else {
    return get_post_meta($post->ID, WpPagePointer::META_URL, true);
  }
}

function the_pointer() {
  echo get_the_pointer();
}

function is_page_pointer() {
  return (bool) get_the_pointer();
}

endif;