<div class="wrap juniper releases">
    <h1><?php esc_html_e( 'Author/Issues', 'juniper' ); ?></h1>

    <p><?php esc_html_e( 'You can view all open issues here.', 'juniper' ); ?></p>

    <?php $this->doOptionsHeader(); ?>

    <?php $repoInfo = $this->getSetting( 'repositories' ); ?>

    <?php $issues = $this->getOpenIssues(); ?>

    <?php if ( count( $issues ) ) { ?>
        <table class="repo-table striped wp-list-table widefat issues" role="presentation">
            <thead>
                <tr>
                    <th></th>
                    <th><?php _e( 'Updated', 'juniper' ); ?></th>
                    <th><?php _e( 'Posted By', 'juniper' ); ?></th>
                    <th><?php _e( 'Repo', 'juniper' ); ?></th>
                    <th><?php _e( 'Title', 'juniper' ); ?></th>
                    <th><?php _e( 'Comments', 'juniper' ); ?></th>
                </tr>
            </thead>
            <tbody>
                
            <?php foreach( $issues as $oneIssue ) { ?>
                <tr class="one-issue">
                    <td><img src="<?php esc_attr_e( $oneIssue->postedBy->avatarUrl ); ?>"></td>
                    <td><?php echo date( 'M jS, Y', $oneIssue->updatedAt ); ?></td>
                    <td><span class="name"><a href="<?php esc_attr_e( $oneIssue->postedBy->userUrl ); ?>"><?php echo $oneIssue->postedBy->user; ?></a></span></td>
                    <td><a href="<?php esc_attr_e( $oneIssue->repoInfo->repoUrl ); ?>" target="_blank"><?php esc_html_e( $oneIssue->repoInfo->fullName ); ?></a></td>
                    
                    <td><a href="<?php esc_attr_e( $oneIssue->url ); ?>" target="_blank"><?php echo $oneIssue->title; ?></a></td>
                    <td><?php echo $oneIssue->comments; ?></td>
                </tr>
            <?php } ?>
            </tbody>
        </table>
    <?php } else { ?>
        <p><?php _e( 'Congratulations, you have no open issues at this time.', 'juniper' ); ?></p>
    <?php } ?>
</div>
