export default class PageStatusModule {
    constructor() {
        this.sessionId = this.generateSessionId();
        this.isChecking = false;
        this.pollingInterval = null;
        this.csrfToken = this.getCsrfToken();
        this.currentFilter = "all";

        this.basePath = this.getModuleBasePath();
        this.init();
    }


    getCsrfToken() {

        const metaTag = document.querySelector('meta[name="typo3-csrf-token"]');
        if (metaTag) {
            return metaTag.getAttribute("content");
        }

        return window.TYPO3?.csrfToken || "";
    }

    generateSessionId() {
        return "sess_" + Math.random().toString(36).substr(2, 9) + "_" + Date.now();
    }


    getModuleBasePath() {


        const url = new URL(window.location.href);
        const pathname = url.pathname;


        return pathname;
    }


    buildRouteUrl(routeName) {
        const basePath = this.getModuleBasePath();

        const cleanBasePath = basePath.replace(/\/$/, "");


        const routePath = `${cleanBasePath}/${routeName}`;


        const currentUrl = new URL(window.location.href);
        const newUrl = new URL(currentUrl);
        newUrl.pathname = routePath;

        return newUrl.toString();
    }

    init() {

        const urlParams = new URLSearchParams(window.location.search);
        this.currentFilter = urlParams.get("filter") || "all";

        this.initFilterHandler();
        this.initPageSelectHandler();
        this.initCheckAllBtn();
        this.initContinueCheckBtn();
        this.initCheckFailedBtn();
        this.initStopBtn();
        this.initCheckSinglePageHandlers();


        window.PageStatusModuleInstance = this;
    }


    startPolling() {
        if (this.pollingInterval) {
            clearInterval(this.pollingInterval);
        }

        this.pollingInterval = setInterval(() => {
            this.checkPollStatus();
        }, 2000);
    }


    async checkPollStatus() {

        const statusUrl = new URL(window.location.href);

        console.log("[PageStatus] Polling for progress...");

        try {
            const response = await fetch(statusUrl.toString(), {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "X-Session-ID": this.sessionId,
                    "X-Requested-With": "XMLHttpRequest",
                    "X-CSRF-Token": this.csrfToken,
                },
                body: JSON.stringify({
                    action: "checkStatus",
                    filter: this.currentFilter || "all",
                }),
            });

            if (!response.ok) {
                console.error("[PageStatus] Polling response not OK:", response.status);

                this.stopPolling();
                this.isChecking = false;


                const checkAllBtn = document.getElementById("checkAllBtn");
                if (checkAllBtn) {
                    checkAllBtn.disabled = false;
                    checkAllBtn.innerHTML = `
            <span class="t3js-icon icon icon-size-small icon-state-default icon-actions-refresh" data-identifier="actions-refresh" aria-hidden="true">
              <span class="icon-markup">
                <svg class="icon-color"><use xlink:href="/_assets/1ee1d3e909b58d32e30dcea666dd3224/Icons/T3Icons/sprites/actions.svg#actions-refresh"></use></svg>
              </span>
            </span>
            Check All Pages
          `;
                }

                this.hideProgress();
                return;
            }

            const data = await response.json();
            console.log("[PageStatus] Polling response:", data);


            if (data.completed !== undefined && data.total !== undefined) {
                this.updateProgress(data.completed, data.total);
            }


            if (data.pagesHtml) {
                const tbody = document.querySelector("#pageStatusTable tbody");
                if (tbody) {
                    tbody.innerHTML = data.pagesHtml;
                    console.log("[PageStatus] Table updated with latest data");

                    this.initCheckSinglePageHandlers();
                }
            }

            if (data.isComplete) {
                this.onCheckComplete(data);
            }
        } catch (error) {
            console.error("[PageStatus] Polling error:", error);

            this.stopPolling();
            this.isChecking = false;


            const checkAllBtn = document.getElementById("checkAllBtn");
            if (checkAllBtn) {
                checkAllBtn.disabled = false;
                checkAllBtn.innerHTML = `
          <span class="t3js-icon icon icon-size-small icon-state-default icon-actions-refresh" data-identifier="actions-refresh" aria-hidden="true">
            <span class="icon-markup">
              <svg class="icon-color"><use xlink:href="/_assets/1ee1d3e909b58d32e30dcea666dd3224/Icons/T3Icons/sprites/actions.svg#actions-refresh"></use></svg>
            </span>
          </span>
          Check All Pages
        `;
            }

            this.hideProgress();
        }
    }


    stopPolling() {
        if (this.pollingInterval) {
            clearInterval(this.pollingInterval);
            this.pollingInterval = null;
        }
    }


    updateProgress(current, total) {
        const progressBar = document.getElementById("checkProgressBar");
        const progressText = document.getElementById("checkProgressText");

        if (progressBar) {
            const percentage = Math.round((current / total) * 100);
            progressBar.style.width = percentage + "%";
            progressBar.setAttribute("aria-valuenow", percentage);
        }

        if (progressText) {
            progressText.textContent = `Checking page ${current} of ${total}... (${Math.round((current / total) * 100)}%)`;
        }
    }


    onCheckComplete(data) {
        this.isChecking = false;
        this.stopPolling();


        this.hideProgress();


        if (data.pagesHtml) {
            const tbody = document.querySelector("#pageStatusTable tbody");
            if (tbody) {
                tbody.innerHTML = data.pagesHtml;
                console.log("[PageStatus] Table updated with new data");
            }
        }


        const checkAllBtn = document.getElementById("checkAllBtn");
        if (checkAllBtn) {
            checkAllBtn.disabled = false;
            checkAllBtn.innerHTML = `
        <span class="t3js-icon icon icon-size-small icon-state-default icon-actions-refresh" data-identifier="actions-refresh" aria-hidden="true">
          <span class="icon-markup">
            <svg class="icon-color"><use xlink:href="/_assets/1ee1d3e909b58d32e30dcea666dd3224/Icons/T3Icons/sprites/actions.svg#actions-refresh"></use></svg>
          </span>
        </span>
        Check All Pages
      `;
        }


        this.initCheckSinglePageHandlers();


        const completed = data.completed || 0;
        const total = data.total || 0;
        this.showNotification(`Completed! Checked ${completed} pages.`, "success");
    }


    initFilterHandler() {
        const filterSelect = document.getElementById("filter");
        if (filterSelect) {
            filterSelect.addEventListener("change", (event) => {
                this.updateFilter(event.target.value);
            });
        }
    }


    updateFilter(filter) {

        this.currentFilter = filter;

        const filterUrl = new URL(window.location.href);

        console.log("[PageStatus] Sending filter update:", filter);
        console.log(
            "[PageStatus] CSRF Token:",
            this.csrfToken ? this.csrfToken.substring(0, 10) + "..." : "none",
        );

        fetch(filterUrl.toString(), {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-Requested-With": "XMLHttpRequest",
                "X-CSRF-Token": this.csrfToken,
            },
            body: JSON.stringify({
                action: "filter",
                filter: filter,
            }),
        })
            .then((response) => response.json())
            .then((data) => {
                if (data.success) {

                    this.updateTableContent(data);
                } else {
                    this.showNotification(
                        "Error: " + (data.message || "Unknown error"),
                        "error",
                    );
                }
            })
            .catch((error) => {
                this.showNotification("Error updating filter: " + error, "error");
            });
    }


    initPageSelectHandler() {
        const pageSelect = document.getElementById("pageSelect");
        if (pageSelect) {
            pageSelect.addEventListener("change", (event) => {
                const pageId = parseInt(event.target.value, 10);
                this.updatePageSelect(pageId);
            });
        }
    }


    updatePageSelect(pageId) {
        const filterUrl = new URL(window.location.href);

        fetch(filterUrl.toString(), {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-Requested-With": "XMLHttpRequest",
                "X-CSRF-Token": this.csrfToken,
            },
            body: JSON.stringify({
                action: "selectPage",
                pageId: pageId,
            }),
        })
            .then((response) => response.json())
            .then((data) => {
                if (data.success) {

                    this.updateTableContent(data);
                } else {
                    this.showNotification(
                        "Error: " + (data.message || "Unknown error"),
                        "error",
                    );
                }
            })
            .catch((error) => {
                this.showNotification(
                    "Error updating page selection: " + error,
                    "error",
                );
            });
    }


    updateTableContent(data) {
        const tableBody = document.querySelector("#pageStatusTable tbody");
        if (tableBody && data.html) {
            tableBody.innerHTML = data.html;
        }


        if (data.statistics) {
            const statTotal = document.querySelector(".stat-total .stat-value");
            const statOnline = document.querySelector(".stat-online .stat-value");
            const statOffline = document.querySelector(".stat-offline .stat-value");

            if (statTotal) statTotal.textContent = data.statistics.total;
            if (statOnline) statOnline.textContent = data.statistics.online;
            if (statOffline) statOffline.textContent = data.statistics.offline;
        }


        this.initCheckSinglePageHandlers();
    }


    initCheckAllBtn() {
        const checkAllBtn = document.getElementById("checkAllBtn");
        if (checkAllBtn) {
            checkAllBtn.addEventListener("click", () => {
                if (this.isChecking) {
                    this.showNotification("Already checking pages...", "info");
                    return;
                }

                if (
                    confirm(
                        "Are you sure you want to check all pages? This may take a while.",
                    )
                ) {
                    this.checkAllPages(checkAllBtn);
                }
            });
        }
    }


    initCheckFailedBtn() {
        const checkFailedBtn = document.getElementById("checkFailedBtn");
        if (checkFailedBtn) {
            checkFailedBtn.addEventListener("click", () => {
                if (this.isChecking) {
                    this.showNotification("Already checking pages...", "info");
                    return;
                }

                if (
                    confirm(
                        "Are you sure you want to retry failed pages? This will only recheck pages from the current selection that previously failed.",
                    )
                ) {
                    this.checkFailedPages(checkFailedBtn);
                }
            });
        }
    }


    initStopBtn() {
        const stopCheckBtn = document.getElementById("stopCheckBtn");
        if (stopCheckBtn) {
            stopCheckBtn.addEventListener("click", () => {
                if (confirm("Are you sure you want to stop checking pages?")) {
                    this.stopCheck();
                }
            });
        }
    }


    initContinueCheckBtn() {
        const continueCheckBtn = document.getElementById("continueCheckBtn");
        if (continueCheckBtn) {
            continueCheckBtn.addEventListener("click", () => {
                if (this.isChecking) {
                    this.showNotification("Already checking pages...", "info");
                    return;
                }

                if (
                    confirm(
                        "Continue checking pages? This will only check pages that are not yet in the database from the current selection.",
                    )
                ) {
                    this.continueCheckPages(continueCheckBtn);
                }
            });
        }
    }


    continueCheckPages(btn) {
        this.isChecking = true;
        const originalContent = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML =
            '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Checking new pages...';


        this.showProgress();


        this.startPolling();


        const filter = this.currentFilter || "all";
        const pageSelect = document.getElementById("pageSelect");
        const selectedPageId = pageSelect ? parseInt(pageSelect.value, 10) : 0;


        const continueUrl = new URL(window.location.href);

        console.log("[PageStatus] Starting continue check pages...");
        console.log("[PageStatus] URL:", continueUrl.toString());
        console.log("[PageStatus] Session ID:", this.sessionId);
        console.log("[PageStatus] Filter:", filter);
        console.log("[PageStatus] Selected Page ID:", selectedPageId);

        fetch(continueUrl.toString(), {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-Requested-With": "XMLHttpRequest",
                "X-Session-ID": this.sessionId,
                "X-CSRF-Token": this.csrfToken,
            },
            body: JSON.stringify({
                action: "continue",
                filter: filter,
                pageId: selectedPageId,
            }),
        })
            .then((response) => {
                console.log(
                    "[PageStatus] Continue check response status:",
                    response.status,
                );
                return response.json();
            })
            .then((data) => {
                console.log("[PageStatus] Continue check response data:", data);
                if (data.success) {

                    if (data.sessionId) {
                        this.sessionId = data.sessionId;
                        console.log("[PageStatus] Updated session ID:", this.sessionId);
                    }

                    if (data.total === 0) {

                        this.showNotification(
                            data.message ||
                            "All pages from current selection are already in the database.",
                            "info",
                        );
                        this.isChecking = false;
                        this.stopPolling();
                        btn.disabled = false;
                        btn.innerHTML = originalContent;
                        this.hideProgress();
                    } else {
                        console.log(
                            "[AJAX] Continue check initiated, polling for progress",
                        );
                        console.log("[PageStatus] Total new pages to check:", data.total);

                        const stopCheckBtn = document.getElementById("stopCheckBtn");
                        if (stopCheckBtn) {
                            stopCheckBtn.style.display = "inline-block";
                        }
                    }
                } else {
                    this.showNotification(
                        "Error: " + (data.message || "Unknown error"),
                        "error",
                    );
                    this.isChecking = false;
                    this.stopPolling();
                    btn.disabled = false;
                    btn.innerHTML = originalContent;
                    this.hideProgress();
                }
            })
            .catch((error) => {
                console.error("[PageStatus] Continue check error:", error);
                this.showNotification("Error checking new pages: " + error, "error");
                this.isChecking = false;
                this.stopPolling();
                btn.disabled = false;
                btn.innerHTML = originalContent;
                this.hideProgress();
            });
    }


    stopCheck() {
        console.log("[PageStatus] Stopping check operation...");


        this.stopPolling();


        const stopUrl = new URL(window.location.href);
        fetch(stopUrl.toString(), {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-Requested-With": "XMLHttpRequest",
                "X-CSRF-Token": this.csrfToken,
            },
            body: JSON.stringify({
                action: "stop",
                sessionId: this.sessionId,
            }),
        })
            .then((response) => response.json())
            .then((data) => {
                console.log("[PageStatus] Stop response:", data);
            })
            .catch((error) => {
                console.error("[PageStatus] Stop error:", error);
            });


        this.isChecking = false;


        this.hideProgress();


        this.showNotification("Page status checking stopped.", "info");


        const checkAllBtn = document.getElementById("checkAllBtn");
        const checkFailedBtn = document.getElementById("checkFailedBtn");
        const stopCheckBtn = document.getElementById("stopCheckBtn");

        if (checkAllBtn) {
            checkAllBtn.disabled = false;
            checkAllBtn.innerHTML = `
        <span class="t3js-icon icon icon-size-small icon-state-default icon-actions-refresh" data-identifier="actions-refresh" aria-hidden="true">
          <svg class="icon-icon-actions-refresh">
            <use xlink:href="/typo3/sysext/backend/Resources/Public/Icons/Actions.svg#actions-refresh"></use>
          </svg>
        </span>
        Check All Pages
      `;
        }

        if (checkFailedBtn) {
            checkFailedBtn.disabled = false;
            checkFailedBtn.innerHTML = `
        <span class="t3js-icon icon icon-size-small icon-state-default icon-actions-exclamation-triangle" data-identifier="actions-exclamation-triangle" aria-hidden="true">
          <svg class="icon-icon-actions-exclamation-triangle">
            <use xlink:href="/typo3/sysext/backend/Resources/Public/Icons/Actions.svg#actions-exclamation-triangle"></use>
          </svg>
        </span>
        Check Failed Pages
      `;
        }

        if (stopCheckBtn) {
            stopCheckBtn.style.display = "none";
        }
    }


    checkFailedPages(btn) {
        this.isChecking = true;
        const originalContent = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML =
            '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Retrying failed pages...';


        this.showProgress();


        this.startPolling();


        const filter = this.currentFilter || "all";
        const pageSelect = document.getElementById("pageSelect");
        const selectedPageId = pageSelect ? parseInt(pageSelect.value, 10) : 0;


        const checkFailedUrl = new URL(window.location.href);

        console.log("[PageStatus] Starting check failed pages...");
        console.log("[PageStatus] URL:", checkFailedUrl.toString());
        console.log("[PageStatus] Session ID:", this.sessionId);
        console.log("[PageStatus] Filter:", filter);
        console.log("[PageStatus] Selected Page ID:", selectedPageId);

        fetch(checkFailedUrl.toString(), {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-Requested-With": "XMLHttpRequest",
                "X-Session-ID": this.sessionId,
                "X-CSRF-Token": this.csrfToken,
            },
            body: JSON.stringify({
                action: "checkFailed",
                filter: filter,
                pageId: selectedPageId,
            }),
        })
            .then((response) => {
                console.log(
                    "[PageStatus] Check failed response status:",
                    response.status,
                );
                return response.json();
            })
            .then((data) => {
                console.log("[PageStatus] Check failed response data:", data);
                if (data.success) {

                    if (data.sessionId) {
                        this.sessionId = data.sessionId;
                        console.log("[PageStatus] Updated session ID:", this.sessionId);
                    }

                    if (data.total === 0) {

                        this.showNotification(
                            data.message || "No failed pages found in current selection.",
                            "info",
                        );
                        this.isChecking = false;
                        this.stopPolling();
                        btn.disabled = false;
                        btn.innerHTML = originalContent;
                        this.hideProgress();
                    } else {
                        console.log("[AJAX] Check failed initiated, polling for progress");
                        console.log(
                            "[PageStatus] Total failed pages to retry:",
                            data.total,
                        );

                        const stopCheckBtn = document.getElementById("stopCheckBtn");
                        if (stopCheckBtn) {
                            stopCheckBtn.style.display = "inline-block";
                        }
                    }
                } else {
                    this.showNotification(
                        "Error: " + (data.message || "Unknown error"),
                        "error",
                    );
                    this.isChecking = false;
                    this.stopPolling();
                    btn.disabled = false;
                    btn.innerHTML = originalContent;
                    this.hideProgress();
                }
            })
            .catch((error) => {
                console.error("[PageStatus] Check failed error:", error);
                this.showNotification("Error checking failed pages: " + error, "error");
                this.isChecking = false;
                this.stopPolling();
                btn.disabled = false;
                btn.innerHTML = originalContent;
                this.hideProgress();
            });
    }


    checkAllPages(btn) {
        this.isChecking = true;
        const originalContent = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML =
            '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Checking all pages...';


        this.showProgress();


        this.startPolling();


        const filter = this.currentFilter || "all";
        const pageSelect = document.getElementById("pageSelect");
        const selectedPageId = pageSelect ? parseInt(pageSelect.value, 10) : 0;


        const checkAllUrl = new URL(window.location.href);

        console.log("[PageStatus] Starting check all...");
        console.log("[PageStatus] URL:", checkAllUrl.toString());
        console.log("[PageStatus] Session ID:", this.sessionId);
        console.log(
            "[PageStatus] CSRF Token:",
            this.csrfToken ? this.csrfToken.substring(0, 10) + "..." : "none",
        );
        console.log("[PageStatus] Filter:", filter);
        console.log("[PageStatus] Selected Page ID:", selectedPageId);

        fetch(checkAllUrl.toString(), {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-Requested-With": "XMLHttpRequest",
                "X-Session-ID": this.sessionId,
                "X-CSRF-Token": this.csrfToken,
            },
            body: JSON.stringify({
                action: "checkAll",
                filter: filter,
                pageId: selectedPageId,
            }),
        })
            .then((response) => {
                console.log("[PageStatus] Check all response status:", response.status);
                return response.json();
            })
            .then((data) => {
                console.log("[PageStatus] Check all response data:", data);
                if (data.success) {

                    if (data.sessionId) {
                        this.sessionId = data.sessionId;
                        console.log("[PageStatus] Updated session ID:", this.sessionId);
                    }
                    console.log("[AJAX] Check all initiated, polling for progress");
                    console.log("[PageStatus] Total pages:", data.total || "unknown");

                    const stopCheckBtn = document.getElementById("stopCheckBtn");
                    if (stopCheckBtn) {
                        stopCheckBtn.style.display = "inline-block";
                    }
                } else {
                    this.showNotification(
                        "Error: " + (data.message || "Unknown error"),
                        "error",
                    );
                    this.isChecking = false;
                    this.stopPolling();
                    btn.disabled = false;
                    btn.innerHTML = originalContent;
                    this.hideProgress();
                }
            })
            .catch((error) => {
                console.error("[PageStatus] Check all error:", error);
                this.showNotification("Error checking pages: " + error, "error");
                this.isChecking = false;
                this.stopPolling();
                btn.disabled = false;
                btn.innerHTML = originalContent;
                this.hideProgress();
            });
    }


    showProgress() {
        const progressContainer = document.getElementById("progressContainer");
        if (progressContainer) {
            progressContainer.style.display = "block";
        }
    }


    hideProgress() {
        const progressContainer = document.getElementById("progressContainer");
        if (progressContainer) {
            progressContainer.style.display = "none";
        }
    }


    initCheckSinglePageHandlers() {
        document.querySelectorAll(".check-single-btn").forEach((button) => {
            button.addEventListener("click", (event) => {
                event.preventDefault();
                const pageId = button.getAttribute("data-page-id");
                this.checkSinglePage(pageId, button);
            });
        });
    }


    checkSinglePage(pageId, button) {
        const originalContent = button.innerHTML;
        button.disabled = true;
        button.innerHTML =
            '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Checking...';


        const checkSingleUrl = new URL(window.location.href);

        console.log("[PageStatus] Checking single page:", pageId);

        fetch(checkSingleUrl.toString(), {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-Requested-With": "XMLHttpRequest",
                "X-Session-ID": this.sessionId,
                "X-CSRF-Token": this.csrfToken,
            },
            body: JSON.stringify({
                action: "checkSingle",
                pageId: parseInt(pageId, 10),
            }),
        })
            .then((response) => {
                console.log(
                    "[PageStatus] Check single response status:",
                    response.status,
                );
                return response.json();
            })
            .then((data) => {
                console.log("[PageStatus] Check single response data:", data);
                if (data.success) {
                    this.showNotification(
                        "Page check completed successfully!",
                        "success",
                    );
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else {
                    this.showNotification("Error: " + data.message, "error");
                    button.disabled = false;
                    button.innerHTML = originalContent;
                }
            })
            .catch((error) => {
                console.error("[PageStatus] Check single error:", error);
                this.showNotification("Error checking page: " + error, "error");
                button.disabled = false;
                button.innerHTML = originalContent;
            });
    }


    showNotification(message, type = "info") {
        const notification = document.createElement("div");
        notification.className = `alert alert-${type} alert-dismissible fade show`;
        notification.setAttribute("role", "alert");
        notification.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        `;

        const container = document.querySelector(".module-header") || document.body;
        container.insertBefore(notification, container.firstChild);

        setTimeout(() => {
            notification.remove();
        }, 5000);
    }


    destroy() {
        this.stopPolling();
    }
}


if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", () => new PageStatusModule());
} else {
    new PageStatusModule();
}


window.addEventListener("beforeunload", () => {
    const module = window.PageStatusModuleInstance;
    if (module) {
        module.destroy();
    }
});
