<?php

namespace WPSPCORELITE\App\Auth\Access;

/**
 * Helper cho Policy (tương đương Illuminate\Auth\Access\HandlesAuthorization).
 *
 *     class PostPolicy {
 *         use HandlesAuthorization;
 *
 *         public function update(\WP_User $user, $post) {
 *             return $user->ID === (int)$post->post_author
 *                 ? $this->allow()
 *                 : $this->deny('Bạn không phải tác giả bài viết này.');
 *         }
 *     }
 */
trait HandlesAuthorization {

	protected function allow(?string $message = null, int|string|null $code = null): Response {
		return Response::allow($message, $code);
	}

	protected function deny(?string $message = null, int|string|null $code = null): Response {
		return Response::deny($message, $code);
	}

	protected function denyWithStatus(int $status, ?string $message = null, int|string|null $code = null): Response {
		return Response::denyWithStatus($status, $message, $code);
	}

	protected function denyAsNotFound(?string $message = null, int|string|null $code = null): Response {
		return Response::denyAsNotFound($message, $code);
	}

}
