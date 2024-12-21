<div class="wrap">
    <h1><?php esc_html_e( 'Author/Releases', 'juniper' ); ?></h1>

    <p><?php esc_html_e( 'You can view all releases here, and sign ones when necessary.', 'juniper' ); ?></p>

    <?php $this->doOptionsHeader(); ?>

    <form method="post" action="options-general.php?page=api-privacy"> 
        <input type="hidden" name="juniper_author" value="1">
        <input type="hidden" name="juniper_author_nonce" value="<?php echo wp_create_nonce( 'juniper' ); ?>">
        <table class="form-table" role="presentation">
            <tbody>
            </tbody>
        </table>
        <input type="submit" id="submit" class="button button-primary" name="submit" value="<?php esc_attr_e( 'Save Changes', 'juniper' ); ?>" />
    </form>
</div>
