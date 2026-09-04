//! A tiny library that exists only to prove the FFI seam works, and to hand PHP the cases the real
//! battle engine never produces on purpose — such as a genuinely null C string.
use std::os::raw::c_char;

#[no_mangle]
pub extern "C" fn rust_hello() -> *mut u8 {
    let message = "Hello from Rust!";
    // Convert to C string and leak memory (since we're returning to C/PHP)
    let c_str = std::ffi::CString::new(message).unwrap();
    c_str.into_raw() as *mut u8
}

/// A genuinely null `char*`, as C sees it.
///
/// PHP's FFI hands a null C pointer to userland as a `FFI\CData` object, not as the PHP value
/// `null`: a client that tests `=== null` lets it through to `FFI::string()`. The battle engine
/// never returns null, so this is the only way to prove that the client's check uses
/// `FFI::isNull()` on a real null pointer.
#[no_mangle]
pub extern "C" fn rust_null_string() -> *mut c_char {
    std::ptr::null_mut()
}
