(function ($) {
  $(function () {
    const button = $('#livepro_pick_preview_image');
    const input = $('#livepro_preview_image_url');
    const image = $('#livepro_preview_image_tag');

    if (!button.length || !input.length || !image.length || typeof wp === 'undefined' || !wp.media) {
      return;
    }

    button.on('click', function () {
      const frame = wp.media({
        title: 'Seleccionar imagen preview',
        button: { text: 'Usar imagen' },
        library: { type: 'image' },
        multiple: false,
      });

      frame.on('select', function () {
        const attachment = frame.state().get('selection').first().toJSON();
        const url = attachment && attachment.url ? String(attachment.url) : '';
        input.val(url);
        if (url) {
          image.attr('src', url).show();
        }
      });

      frame.open();
    });
  });
})(jQuery);
