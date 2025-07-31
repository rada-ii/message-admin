/**
 * Message Admin - Admin Panel JavaScript
 * Handles dynamic interactions in the admin interface
 */

jQuery(document).ready(function ($) {
  console.log("Message Admin script loaded");

  // Toggle specific pages options
  $('input[name="page_targeting_type"]').change(function () {
    if ($(this).val() === "specific") {
      $("#specific-pages").show();
    } else {
      $("#specific-pages").hide();
    }
  });

  // Copy shortcode to clipboard functionality
  $(document).on("click", ".shortcode-copy", function (e) {
    e.preventDefault();

    var $this = $(this);
    var text = $this.text();

    // Modern clipboard API
    if (navigator.clipboard) {
      navigator.clipboard
        .writeText(text)
        .then(function () {
          showCopyNotification($this, "Shortcode copied!");
        })
        .catch(function (err) {
          // Fallback for older browsers
          fallbackCopyTextToClipboard(text, $this);
        });
    } else {
      // Fallback for older browsers
      fallbackCopyTextToClipboard(text, $this);
    }
  });

  // Fallback copy function for older browsers
  function fallbackCopyTextToClipboard(text, $element) {
    var textArea = document.createElement("textarea");
    textArea.value = text;

    // Avoid scrolling to bottom
    textArea.style.top = "0";
    textArea.style.left = "0";
    textArea.style.position = "fixed";

    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();

    try {
      var successful = document.execCommand("copy");
      if (successful) {
        showCopyNotification($element, "Shortcode copied!");
      } else {
        showCopyNotification($element, "Copy failed", "error");
      }
    } catch (err) {
      showCopyNotification($element, "Copy not supported", "error");
    }

    document.body.removeChild(textArea);
  }

  // Show copy notification
  function showCopyNotification($element, message, type) {
    type = type || "success";

    // Remove existing notification
    $(".copy-notification").remove();

    // Create notification element
    var $notification = $(
      '<div class="copy-notification copy-' + type + '">' + message + "</div>"
    );

    // Position it near the clicked element
    var offset = $element.offset();
    $notification.css({
      position: "absolute",
      top: offset.top - 30,
      left: offset.left,
      zIndex: 9999,
      background: type === "success" ? "#46b450" : "#dc3232",
      color: "#fff",
      padding: "5px 10px",
      borderRadius: "3px",
      fontSize: "12px",
      whiteSpace: "nowrap",
    });

    // Add to body and animate
    $("body").append($notification);

    // Fade out after 2 seconds
    setTimeout(function () {
      $notification.fadeOut(300, function () {
        $(this).remove();
      });
    }, 2000);
  }

  // Confirm delete action
  $(".button-link-delete").click(function (e) {
    if (
      !confirm(
        "Are you sure you want to delete this message? This action cannot be undone."
      )
    ) {
      e.preventDefault();
    }
  });

  // Status toggle functionality
  $(document).on("click", ".status-toggle", function () {
    var $button = $(this);
    var messageId = $button.data("message-id");
    var currentStatus = $button.data("current-status");
    var newStatus = currentStatus === "active" ? "inactive" : "active";

    $button.addClass("loading").prop("disabled", true);

    $.ajax({
      url: messageAdmin.ajaxurl,
      type: "POST",
      data: {
        action: "toggle_message_status",
        message_id: messageId,
        new_status: newStatus,
        nonce: messageAdmin.nonce,
      },
      success: function (response) {
        if (response.success) {
          $button
            .removeClass("status-" + currentStatus)
            .addClass("status-" + newStatus);
          $button
            .find(".status-text")
            .text(newStatus.charAt(0).toUpperCase() + newStatus.slice(1));
          $button.data("current-status", newStatus);
          $button.closest("tr").attr("data-status", newStatus);
          showNotification("Status updated successfully", "success");
        } else {
          showNotification("Failed to update status", "error");
        }
      },
      error: function () {
        showNotification("Network error occurred", "error");
      },
      complete: function () {
        $button.removeClass("loading").prop("disabled", false);
      },
    });
  });

  // Enhanced checkbox/radio interactions
  $('input[type="checkbox"]').change(function () {
    var $this = $(this);

    // If "all users" is checked, uncheck specific roles
    if ($this.val() === "all" && $this.prop("checked")) {
      $('input[name="message_user_roles[]"]:not([value="all"])').prop(
        "checked",
        false
      );
    }
    // If any specific role is checked, uncheck "all users"
    else if (
      $this.attr("name") === "message_user_roles[]" &&
      $this.val() !== "all" &&
      $this.prop("checked")
    ) {
      $('input[value="all"][name="message_user_roles[]"]').prop(
        "checked",
        false
      );
    }
  });

  // Search functionality for pages/posts (if many items)
  if ($('#specific-pages input[type="checkbox"]').length > 10) {
    var $searchInput = $(
      '<input type="text" placeholder="Search pages..." class="page-search-input">'
    );
    $("#specific-pages").prepend($searchInput);

    $searchInput.on("input", function () {
      var searchTerm = $(this).val().toLowerCase();
      $("#specific-pages label").each(function () {
        var text = $(this).text().toLowerCase();
        $(this).toggle(text.indexOf(searchTerm) > -1);
      });
    });
  }

  // Form validation
  function validateMessageForm() {
    var isValid = true;
    var errors = [];

    // Check title
    var title = $("#message_title").val().trim();
    if (!title) {
      errors.push("Title is required");
      $("#message_title").addClass("error");
      isValid = false;
    } else {
      $("#message_title").removeClass("error");
    }

    // Check content
    var content = "";
    if (typeof tinyMCE !== "undefined" && tinyMCE.get("message_content")) {
      content = tinyMCE.get("message_content").getContent();
    } else {
      content = $("#message_content").val();
    }

    if (!content || content.trim() === "") {
      errors.push("Content is required");
      $("#message_content").addClass("error");
      isValid = false;
    } else {
      $("#message_content").removeClass("error");
    }

    // Show errors
    if (errors.length > 0) {
      showNotification(
        "Please fix the following errors: " + errors.join(", "),
        "error"
      );
    }

    return isValid;
  }

  // Form submission validation
  $(".message-form").on("submit", function (e) {
    if (!validateMessageForm()) {
      e.preventDefault();
      return false;
    }
  });

  // Notification function
  function showNotification(message, type) {
    type = type || "info";

    var $notification = $(
      '<div class="admin-notification admin-notification-' +
        type +
        '">' +
        message +
        "</div>"
    );

    var bgColor = "#17a2b8"; // info
    if (type === "success") bgColor = "#28a745";
    else if (type === "error") bgColor = "#dc3545";

    $notification.css({
      position: "fixed",
      top: "50px",
      right: "20px",
      padding: "12px 20px",
      borderRadius: "6px",
      color: "white",
      fontWeight: "500",
      zIndex: "10000",
      background: bgColor,
      opacity: "0",
      transform: "translateX(100%)",
      transition: "all 0.3s ease",
    });

    $("body").append($notification);

    // Animate in
    setTimeout(function () {
      $notification.css({
        opacity: "1",
        transform: "translateX(0)",
      });
    }, 100);

    // Animate out and remove
    setTimeout(function () {
      $notification.css({
        opacity: "0",
        transform: "translateX(100%)",
      });
      setTimeout(function () {
        $notification.remove();
      }, 300);
    }, 3000);
  }

  // Select all functionality
  $("#select-all-messages").change(function () {
    var isChecked = $(this).prop("checked");
    $('input[name="message_ids[]"]:visible').prop("checked", isChecked);
  });

  // Search messages functionality
  $("#search-messages").on("input", function () {
    var searchTerm = $(this).val().toLowerCase();

    $(".message-row").each(function () {
      var $row = $(this);
      var title = $row.find(".message-title").text().toLowerCase();
      var content = $row.find(".message-content-preview").text().toLowerCase();

      var isVisible =
        title.includes(searchTerm) || content.includes(searchTerm);
      $row.toggle(isVisible);
    });
  });

  // Filter by status
  $("#filter-status").change(function () {
    var status = $(this).val();

    $(".message-row").each(function () {
      var $row = $(this);
      var rowStatus = $row.data("status");
      $row.toggle(!status || rowStatus === status);
    });
  });

  console.log("Message Admin script initialization complete");
});

// Global functions for external use
window.MessageAdmin = {
  // Copy shortcode programmatically
  copyShortcode: function (messageId) {
    var shortcode = '[message_admin id="' + messageId + '"]';
    if (navigator.clipboard) {
      navigator.clipboard.writeText(shortcode);
    }
    return shortcode;
  },

  // Toggle message status via AJAX
  toggleStatus: function (messageId) {
    console.log("Toggle status for message:", messageId);
    jQuery('.status-toggle[data-message-id="' + messageId + '"]').click();
  },
};

// Global functions for backwards compatibility
window.confirmBulkAction = function () {
  var action = document.getElementById("bulk-action").value;
  var checked = document.querySelectorAll(
    'input[name="message_ids[]"]:checked'
  );

  if (!action) {
    alert("Please select an action.");
    return false;
  }

  if (checked.length === 0) {
    alert("Please select at least one message.");
    return false;
  }

  if (action === "delete") {
    return confirm(
      "Are you sure you want to delete " +
        checked.length +
        " message(s)? This action cannot be undone."
    );
  }

  return confirm(
    "Are you sure you want to " + action + " " + checked.length + " message(s)?"
  );
};

window.clearCache = function () {
  if (confirm("Clear all Message Admin cache?")) {
    jQuery.post(
      messageAdmin.ajaxurl,
      {
        action: "clear_message_admin_cache",
        nonce: messageAdmin.nonce,
      },
      function (response) {
        if (response.success) {
          location.reload();
        }
      }
    );
  }
};
