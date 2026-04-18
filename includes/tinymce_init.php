<?php
/**
 * TinyMCE Editor Initialization - Shared include for exam/dissertation rich text editing
 * Usage: include_once 'includes/tinymce_init.php';
 * Adds TinyMCE editor to all <textarea class="tinymce"> elements
 */
?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.8.3/tinymce.min.js" referrerpolicy="origin"></script>
<script>
tinymce.init({
  selector: 'textarea.tinymce',
  height: 350,
  menubar: true,
  plugins: 'anchor autolink charmap codesample emoticons fullscreen help image insertdatetime link lists media preview searchreplace table visualblocks wordcount advlist code template',
  toolbar: 'undo redo | blocks fontfamily fontsize | bold italic underline strikethrough | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | link image media table | codesample emoticons charmap | code fullscreen preview | removeformat help',
  content_style: 'body { font-family:Inter,Arial,sans-serif; font-size:14px; }',
  branding: false,
  resize: true,
  image_title: true,
  automatic_uploads: true,
  file_picker_types: 'image',
  images_upload_url: '/api/upload_image.php',
  images_upload_handler: function (blobInfo, success, failure) {
    var xhr = new XMLHttpRequest();
    xhr.withCredentials = false;
    xhr.open('POST', '/api/upload_image.php');
    xhr.onload = function() {
      if (xhr.status != 200) { failure('HTTP Error: ' + xhr.status); return; }
      var json = JSON.parse(xhr.responseText);
      if (!json || typeof json.location != 'string') { failure('Invalid response'); return; }
      success(json.location);
    };
    var formData = new FormData();
    formData.append('file', blobInfo.blob(), blobInfo.filename());
    xhr.send(formData);
  },
  templates: [
    { title: 'Essay Template', description: 'Essay answer structure', content: '<p><strong>Introduction:</strong></p><p></p><p><strong>Main Body:</strong></p><p></p><p><strong>Conclusion:</strong></p>' },
    { title: 'Short Answer', description: 'Short answer structure', content: '<p><strong>Answer:</strong></p><p></p>' }
  ],
  setup: function (editor) {
    editor.on('change', function () { editor.save(); });
  }
});
</script>
