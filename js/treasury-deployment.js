/**
 * @file
 * Handles inline treasury deployment for Group Treasury module.
 *
 * This file reuses deployment patterns and helper functions from
 * safe_smart_accounts/js/safe-deployment.js via library dependency.
 * Treasury-specific behavior: redirects to Group treasury tab after deployment.
 */

(function (Drupal, drupalSettings) {
  'use strict';

  /**
   * Deploy a newly created treasury Safe account.
   *
   * This function follows the same pattern as deployCreatedSafe() from
   * safe-deployment.js but redirects to the Group treasury tab instead
   * of the user's Safe account manage page.
   *
   * @param {number} safeAccountId - The Safe account entity ID
   * @param {number} groupId - The Group entity ID
   */
  async function deployCreatedTreasury(safeAccountId, groupId) {
    try {
      // Update step 2 - MetaMask prompt (use exported function)
      Drupal.safeDeployment.updateDeploymentStep(
        2,
        "in-progress",
        "Waiting for MetaMask signature...",
        "Please check your wallet"
      );

      // Check for Web3 provider
      if (typeof window.ethereum === "undefined") {
        throw new Error("Please install MetaMask or another Web3 wallet");
      }

      // Request account access
      const accounts = await window.ethereum.request({
        method: "eth_requestAccounts",
      });
      const userAddress = accounts[0];
      const checksumAddress = ethers.getAddress(userAddress);

      // Create ethers provider
      const provider = new ethers.BrowserProvider(window.ethereum);

      // Get network information
      const network = await provider.getNetwork();
      const chainId = Number(network.chainId);

      // Verify we're on Sepolia testnet
      if (chainId !== 11155111) {
        throw new Error("Please switch to Sepolia testnet in your wallet");
      }

      // Get the Safe configuration from Drupal (use exported function)
      const safeConfig = await Drupal.safeDeployment.getSafeConfiguration(safeAccountId);
      if (!safeConfig) {
        throw new Error("Could not retrieve Safe configuration");
      }

      // Prepare SafeDeployer for encoding and prediction (use exported class)
      const safeDeployer = new Drupal.safeDeployment.SafeDeployer(
        provider,
        await provider.getSigner()
      );

      // Prepare deployment parameters
      const owners = safeConfig.signers || [checksumAddress];
      const threshold = safeConfig.threshold || 1;
      const saltNonce = safeConfig.salt_nonce || 0;

      // Encode setup data and predict address
      const setupData = safeDeployer.encodeSetupCall(owners, threshold);
      const predictedAddress = await safeDeployer.predictSafeAddress(
        owners,
        threshold,
        saltNonce
      );

      console.log("[Treasury Deployment] Predicted address:", predictedAddress);

      // Send the deployment transaction - this triggers MetaMask prompt
      const tx = await safeDeployer.proxyFactory.createProxyWithNonce(
        safeDeployer.safeAddresses.SAFE_SINGLETON,
        setupData,
        saltNonce
      );

      // Transaction submitted! User has signed in MetaMask
      const txHash = tx.hash;
      console.log("[Treasury Deployment] Transaction submitted:", txHash);

      // Mark step 2 complete now that user has submitted the transaction
      Drupal.safeDeployment.updateDeploymentStep(2, "completed", "Transaction submitted");

      // Update step 3 - Deploying to blockchain
      Drupal.safeDeployment.updateDeploymentStep(
        3,
        "in-progress",
        "Deploying treasury to Sepolia testnet...",
        "Transaction sent, waiting for confirmation..."
      );

      // Show Etherscan link immediately
      const etherscanUrl = `https://sepolia.etherscan.io/tx/${txHash}`;
      Drupal.safeDeployment.updateDeploymentStep(
        3,
        "in-progress",
        "Waiting for blockchain confirmation...",
        `<a href="${etherscanUrl}" target="_blank" rel="noopener noreferrer">View on Etherscan</a> (Est. 30 seconds)`
      );

      // Now wait for the transaction to be mined
      const receipt = await tx.wait();
      console.log("[Treasury Deployment] Transaction confirmed on blockchain");

      // Get the actual Safe address from the receipt
      let safeAddress = predictedAddress;
      try {
        const iface = new ethers.Interface([
          "event ProxyCreation(address proxy, address singleton)",
        ]);
        for (const log of receipt.logs) {
          try {
            const parsed = iface.parseLog({
              topics: log.topics,
              data: log.data,
            });
            if (parsed && parsed.name === "ProxyCreation") {
              safeAddress = ethers.getAddress(parsed.args.proxy);
              console.log(
                "[Treasury Deployment] Safe address from event:",
                safeAddress
              );
              break;
            }
          } catch (e) {
            continue;
          }
        }
      } catch (e) {
        console.warn(
          "[Treasury Deployment] Could not parse ProxyCreation event:",
          e.message
        );
      }

      // Update the Drupal entity (use exported function)
      const deploymentResult = await Drupal.safeDeployment.updateSafeAccountEntity(
        safeAccountId,
        safeAddress,
        "deployed",
        txHash
      );

      if (deploymentResult.success) {
        Drupal.safeDeployment.updateDeploymentStep(
          3,
          "completed",
          "Deployment confirmed on blockchain"
        );
        Drupal.safeDeployment.updateDeploymentStep(
          4,
          "completed",
          "Treasury deployed successfully!",
          `Address: ${safeAddress}<br><a href="${etherscanUrl}" target="_blank" rel="noopener noreferrer">View on Etherscan</a>`
        );

        // Redirect to Group treasury tab after 2 seconds
        setTimeout(() => {
          window.location.href = `/group/${groupId}/treasury`;
        }, 2000);
      } else {
        throw new Error(
          deploymentResult.error || "Failed to update Safe account in Drupal"
        );
      }
    } catch (error) {
      console.error("[Treasury Deployment] Deployment failed:", error);
      const currentStep = Drupal.safeDeployment.getCurrentStep();
      Drupal.safeDeployment.updateDeploymentStep(
        currentStep,
        "error",
        `Treasury deployment failed: ${error.message}`
      );
    }
  }

  /**
   * Drupal behavior to handle treasury deployment trigger from AJAX form submission.
   * Watches for drupalSettings.groupTreasury.triggerDeployment flag.
   */
  Drupal.behaviors.treasuryDeploymentTrigger = {
    attach: function (context, settings) {
      // Check if treasury deployment should be triggered
      if (settings.groupTreasury?.triggerDeployment) {
        console.log(
          "[Treasury Deployment] Deployment trigger detected in drupalSettings"
        );

        // Clear the flag so this doesn't re-trigger on subsequent AJAX calls
        delete settings.groupTreasury.triggerDeployment;

        // Update step 1 to completed status
        const step1 = document.getElementById("step-1");
        if (step1) {
          step1.setAttribute("data-status", "completed");
          const icon = step1.querySelector(".step-icon");
          const text = step1.querySelector(".step-text");
          if (icon) icon.textContent = "✅";
          if (text) text.textContent = "Treasury configuration saved";
        }

        // Dispatch event to trigger deployment
        document.dispatchEvent(
          new CustomEvent("treasuryCreated", {
            detail: {
              safeAccountId: settings.groupTreasury.safeAccountId,
              groupId: settings.groupTreasury.groupId,
            },
          })
        );
      }
    },
  };

  /**
   * Listen for treasury creation event from create form (AJAX).
   * Uses vanilla JavaScript (no jQuery) since Drupal 10 removed jQuery from core.
   */
  document.addEventListener("treasuryCreated", async function (event) {
    console.log(
      "[Treasury Deployment] Treasury created, starting deployment...",
      event.detail
    );

    const safeAccountId = event.detail.safeAccountId;
    const groupId = event.detail.groupId;

    if (!safeAccountId || !groupId) {
      console.error("[Treasury Deployment] Missing required data for deployment");
      Drupal.safeDeployment.updateDeploymentStep(2, "error", "Missing required information");
      return;
    }

    // Start deployment automatically
    await deployCreatedTreasury(safeAccountId, groupId);
  });

})(Drupal, drupalSettings);
