/**
 * Message Admin - Frontend JavaScript
 * Handles dismiss functionality and animations
 */

jQuery(document).ready(function ($) {
  // Handle dismiss button clicks
  $(document).on("click", ".message-admin-dismiss", function (e) {
    e.preventDefault();

    var $button = $(this);
    var $message = $button.closest(".message-admin-display");
    var messageId = $message.data("message-id");

    if (!messageId) {
      console.error("Message Admin: No message ID found");
      return;
    }

    // Disable button to prevent double clicks
    $button.prop("disabled", true);

    // Add loading state
    $message.addClass("message-admin-dismissing");

    // Send AJAX request to dismiss message
    $.ajax({
      url: messageAdminFrontend.ajaxurl,
      type: "POST",
      data: {
        action: "dismiss_message_admin",
        message_id: messageId,
        nonce: messageAdminFrontend.nonce,
      },
      success: function (response) {
        if (response.success) {
          // Animate message removal
          $message.fadeOut(300, function () {
            $(this).remove();
          });
        } else {
          console.error(
            "Message Admin: Failed to dismiss message",
            response.data
          );
          // Re-enable button on error
          $button.prop("disabled", false);
          $message.removeClass("message-admin-dismissing");
        }
      },
      error: function (xhr, status, error) {
        console.error("Message Admin: AJAX error", error);
        // Re-enable button on error
        $button.prop("disabled", false);
        $message.removeClass("message-admin-dismissing");
      },
    });
  });

  // Add smooth animations for messages
  $(".message-admin-display").each(function () {
    var $message = $(this);

    // Add entrance animation
    $message.addClass("message-admin-fade-in");

    // Add hover effects for dismissible messages
    if ($message.hasClass("message-admin-dismissible")) {
      $message.addClass("message-admin-hover-enabled");
    }
  });

  // Auto-hide messages with data-auto-hide attribute
  $(".message-admin-display[data-auto-hide]").each(function () {
    var $message = $(this);
    var delay = parseInt($message.data("auto-hide")) || 5000;

    setTimeout(function () {
      if ($message.hasClass("message-admin-dismissible")) {
        $message.find(".message-admin-dismiss").trigger("click");
      } else {
        $message.fadeOut(300);
      }
    }, delay);
  });

  // Add keyboard accessibility for dismiss buttons
  $(document).on("keydown", ".message-admin-dismiss", function (e) {
    // Trigger click on Enter or Space
    if (e.which === 13 || e.which === 32) {
      e.preventDefault();
      $(this).trigger("click");
    }
  });

  // Handle window resize for responsive messages
  var resizeTimer;
  $(window).on("resize", function () {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(function () {
      $(".message-admin-display").each(function () {
        var $message = $(this);
        // Trigger custom resize event for messages
        $message.trigger("messageResize");
      });
    }, 250);
  });

  // Custom event handlers for developers
  $(document).on("messageShown", ".message-admin-display", function () {
    // Custom event fired when message is shown
    console.log("Message Admin: Message shown", $(this).data("message-id"));
  });

  $(document).on("messageDismissed", ".message-admin-display", function () {
    // Custom event fired when message is dismissed
    console.log("Message Admin: Message dismissed", $(this).data("message-id"));
  });

  // Trigger shown event for existing messages
  $(".message-admin-display").trigger("messageShown");
});

// Global functions for external use
window.MessageAdminFrontend = {
  // Programmatically dismiss a message
  dismissMessage: function (messageId) {
    var $message = $(
      '.message-admin-display[data-message-id="' + messageId + '"]'
    );
    if ($message.length) {
      $message.find(".message-admin-dismiss").trigger("click");
      return true;
    }
    return false;
  },

  // Show a custom message (for developers)
  showMessage: function (content, options) {
    options = options || {};

    var messageClass = "message-admin-display message-admin-custom";
    if (options.type) {
      messageClass += " message-type-" + options.type;
    }
    if (options.dismissible) {
      messageClass += " message-admin-dismissible";
    }

    var dismissButton = options.dismissible
      ? '<button class="message-admin-dismiss" aria-label="Close message">&times;</button>'
      : "";

    var $message = jQuery(
      '<div class="' +
        messageClass +
        '" data-message-id="custom">' +
        dismissButton +
        '<div class="message-admin-content">' +
        content +
        "</div>" +
        "</div>"
    );

    var container = options.container || "body";
    jQuery(container).prepend($message);

    // Add entrance animation
    $message.addClass("message-admin-fade-in");

    // Auto-hide if specified
    if (options.autoHide) {
      setTimeout(function () {
        if (options.dismissible) {
          $message.find(".message-admin-dismiss").trigger("click");
        } else {
          $message.fadeOut(300, function () {
            $(this).remove();
          });
        }
      }, options.autoHide);
    }

    return $message;
  },

  // Hide all messages
  hideAllMessages: function () {
    jQuery(".message-admin-display").each(function () {
      var $message = jQuery(this);
      if ($message.hasClass("message-admin-dismissible")) {
        $message.find(".message-admin-dismiss").trigger("click");
      } else {
        $message.fadeOut(300);
      }
    });
  },

  // Get dismissed message IDs
  getDismissedMessages: function () {
    var cookies = document.cookie.split(";");
    for (var i = 0; i < cookies.length; i++) {
      var cookie = cookies[i].trim();
      if (cookie.indexOf("dismissed_messages=") === 0) {
        try {
          return JSON.parse(
            decodeURIComponent(cookie.substring("dismissed_messages=".length))
          );
        } catch (e) {
          return [];
        }
      }
    }
    return [];
  },
};
