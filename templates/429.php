<?php
ob_start();

$retrySeconds = isset($retrySeconds) ? (int)$retrySeconds : 0;
$isPermanent = !empty($isPermanent);
$blockedUntil = $blockedUntil ?? null;

$retryLabel = 'a short while';
if (!$isPermanent && $retrySeconds > 0) {
    $minutes = (int)ceil($retrySeconds / 60);
    if ($minutes < 60) {
        $retryLabel = $minutes . ' minute' . ($minutes === 1 ? '' : 's');
    } else {
        $hours = (int)ceil($minutes / 60);
        $retryLabel = $hours . ' hour' . ($hours === 1 ? '' : 's');
    }
}
?>

<div class="max-w-2xl mx-auto bg-[#0f172a] border-2 border-[#0060ff] shadow-[0px_0px_38.5px_14px_#0060ff20] rounded-lg shadow-md p-8 mt-8 text-center">
  <h1 class="text-3xl mb-3 text-white">429 - Too Many Requests</h1>
  <p class="mb-4 text-white">
    Download attempts from your account/IP exceeded the allowed rate.
  </p>

  <?php if ($isPermanent): ?>
    <p class="mb-2 text-red-400 font-semibold">This address has been permanently blocked.</p>
    <p class="mb-4 text-gray-300 text-sm">If this is unexpected, contact the Evolution X team with your IP and user ID.</p>
  <?php else: ?>
    <p class="mb-2 text-yellow-300">Please wait about <span class="font-semibold"><?php echo htmlspecialchars($retryLabel); ?></span> before trying again.</p>
    <?php if (!empty($blockedUntil)): ?>
      <p class="mb-4 text-gray-300 text-sm">Block expires at: <?php echo htmlspecialchars($blockedUntil); ?> server time</p>
    <?php endif; ?>
  <?php endif; ?>

  <div class="flex items-center justify-center gap-4 mt-6">
    <a href="/" class="inline-flex h-10 px-6 items-center justify-center rounded-full bg-[#0060ff] text-lg text-white transition-all duration-300 hover:bg-[#004bb5]">
      Return Home
    </a>
  </div>
</div>

<?php
$content = ob_get_clean();
$page_title = 'Too Many Requests';
include 'layout.php';
?>
