<div class="wrap juniper">
    <h1><?php esc_html_e( 'Author/Releases', 'juniper' ); ?></h1>

    <p><?php esc_html_e( 'You can view and sign all releases here.', 'juniper' ); ?></p>

    <?php $this->doOptionsHeader(); ?>

    <?php $repoInfo = $this->getSetting( 'repositories' ); ?>

    <h2><?php _e( 'Sign Archives', 'juniper' ); ?></h2>

     <p><?php _e( 'To digitally sign the archives below, enter the passphrase for your private key and click Sign', 'juniper' ); ?></p>

    <form class="sign-form" method="post" action=""> 
        <input type="hidden" name="juniper_author_nonce" value="<?php echo wp_create_nonce( 'juniper' ); ?>">

        <div class="one-setting">
            <?php _e( 'Enter password to use private key', 'juniper' ); ?></label>
            <input type="password" name="juniper_private_pw_1" id="juniper_private_pw_1" />  <label for="juniper_private_pw_1">
            <a href="#" class="button button-primary digitally-sign">Sign</a>
        </div>
    </form>   

    <div class="progress">
        <div class="bar">10%</div>
    </div> 
    
    <?php foreach( $this->getSetting( 'releases' ) as $repo => $releaseInfo ) { ?>
        <h2><?php echo esc_html( $repoInfo[ $repo ]->pluginName ); ?></h2>
        <table class="repo-table striped wp-list-table widefat releases" role="presentation">
            <thead>
                <tr>
                    <th><?php _e( 'Tag', 'juniper' ); ?></th>
                    <th><?php _e( 'Release Info', 'juniper' ); ?></th>
                    <th><?php _e( 'Package', 'juniper' ); ?></th>
                    <th><?php _e( 'Release Date', 'juniper' ); ?></th>
                    <th><?php _e( 'Signed', 'juniper' ); ?></th>
                </tr>
            </thead>
            <tbody>
                
            <?php foreach( $releaseInfo as $actualRelease ) { ?>
                <?php $signed = ( !empty( $actualRelease->is_signed ) ); ?>
                <tr class="one-release<?php if ( $signed ) echo ' signed'; else echo ' unsigned'; ?>" data-repo="<?php esc_attr_e( $repo ); ?>" data-tag="<?php esc_attr_e( $actualRelease->tag_name ); ?>">
                    <td><?php echo esc_html( $actualRelease->tag_name ); ?></td>
                    <td><?php echo esc_html( $actualRelease->name ); ?></td>
                    
                    <td class="package"><?php echo esc_html( $actualRelease->assets[0]->name ); ?></td>
                    <td><?php echo date( 'M jS, Y', strtotime( $actualRelease->published_at ) ); ?></td>
                    <?php if ( $signed ) { ?>
                        <td class="yesno"><span class="green"><?php _e( 'Yes', 'juniper' ); ?></span></td>
                    <?php } else { ?>
                        <td class="yesno"><span class="red"><?php _e( 'No', 'juniper' ); ?></span></td>
                    <?php } ?>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    <?php }?> 
</div>
