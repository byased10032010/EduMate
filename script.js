// Theme toggle initialization and listeners
const themeToggles = document.querySelectorAll(".theme-switch__checkbox");
const savedTheme = localStorage.getItem("FutureToppers-theme");
const prefersDark = window.matchMedia("(prefers-color-scheme: dark)").matches;
const heroModes = document.querySelectorAll(".hero-mode");
const heroStats = document.querySelectorAll(".hero-stat");
const channels = document.querySelectorAll(".channel");
const conversationTitle = document.querySelector("[data-conversation-title]");
const conversationStatus = document.querySelector("[data-conversation-status]");
const messageList = document.querySelector("[data-message-list]");
const messageForm = document.querySelector(".message-form");
const messageInput = document.querySelector("[data-message-input]");
const reactionButtons = document.querySelectorAll(".hero-reactions button");
const sidebarActions = document.querySelectorAll(".sidebar-actions button");

// Custom Modes messages state
const modeMessages = {
    Teams: {
        title: "App Team",
        status: "4 members online",
        input: "Ship the update today?",
        messages: [
            ["Maya", "Homepage layout is ready. Can you check the chat preview?", "incoming"],
            ["You", "Looks polished. I added quick actions and responsive spacing.", "outgoing"]
        ]
    },
    Friends: {
        title: "Friends",
        status: "7 members online",
        input: "Movie plan at 8?",
        messages: [
            ["Riya", "I saved seats for tonight.", "incoming"],
            ["You", "Perfect. I will send the location in the group.", "outgoing"]
        ]
    },
    Support: {
        title: "Support Desk",
        status: "12 tickets active",
        input: "Send setup guide",
        messages: [
            ["Customer", "Can I move my workspace to a team plan?", "incoming"],
            ["You", "Yes. I can share the upgrade steps now.", "outgoing"]
        ]
    }
};

// Theme Toggle
const setTheme = (theme) => {
    document.documentElement.dataset.theme = theme;
    themeToggles.forEach((toggle) => {
        toggle.checked = theme === "dark";
    });
};

setTheme(savedTheme || (prefersDark ? "dark" : "light"));

themeToggles.forEach((toggle) => {
    toggle.addEventListener("change", () => {
        const nextTheme = toggle.checked ? "dark" : "light";
        setTheme(nextTheme);
        localStorage.setItem("FutureToppers-theme", nextTheme);
    });
});

// ---------- Mobile / tablet hamburger drawer ----------
const navToggle = document.querySelector(".nav-toggle");
const mobileDrawer = document.getElementById("mobileDrawer");
const navOverlay = document.getElementById("navOverlay");
const drawerCloseBtn = mobileDrawer ? mobileDrawer.querySelector(".mobile-drawer-close") : null;
const drawerLinks = mobileDrawer ? mobileDrawer.querySelectorAll("[data-drawer-link]") : null;
const firstFocusableInDrawer = mobileDrawer ? mobileDrawer.querySelector(".mobile-drawer-head .logo") : null;
let lastFocusedBeforeOpen = null;

const isDrawerOpen = () => navToggle && navToggle.classList.contains("is-open");

const setNavOpen = (open) => {
    if (!navToggle || !mobileDrawer || !navOverlay) return;

    if (open && !isDrawerOpen()) {
        lastFocusedBeforeOpen = document.activeElement;
    }

    navToggle.classList.toggle("is-open", open);
    navOverlay.classList.toggle("is-open", open);
    mobileDrawer.classList.toggle("is-open", open);
    navToggle.setAttribute("aria-expanded", String(open));
    navToggle.setAttribute("aria-label", open ? "Close menu" : "Open menu");
    mobileDrawer.setAttribute("aria-hidden", String(!open));

    if (open) {
        const scrollbarW = window.innerWidth - document.documentElement.clientWidth;
        document.documentElement.style.setProperty("--nav-scrollbar-w", `${scrollbarW}px`);
        document.body.classList.add("nav-open");
        navOverlay.removeAttribute("hidden");
    } else {
        document.body.classList.remove("nav-open");
        document.documentElement.style.removeProperty("--nav-scrollbar-w");
    }

    if (open) {
        mobileDrawer.removeAttribute("inert");
        requestAnimationFrame(() => {
            const target = drawerCloseBtn || firstFocusableInDrawer;
            if (target && typeof target.focus === "function") {
                target.focus({ preventScroll: true });
            }
        });
    } else {
        mobileDrawer.setAttribute("inert", "");
        if (lastFocusedBeforeOpen && typeof lastFocusedBeforeOpen.focus === "function") {
            lastFocusedBeforeOpen.focus({ preventScroll: true });
        }
        lastFocusedBeforeOpen = null;
    }
};

if (navToggle && mobileDrawer && navOverlay) {
    navToggle.addEventListener("click", () => setNavOpen(!isDrawerOpen()));
    navOverlay.addEventListener("click", () => setNavOpen(false));

    if (drawerCloseBtn) {
        drawerCloseBtn.addEventListener("click", () => setNavOpen(false));
    }

    if (drawerLinks) {
        drawerLinks.forEach((link) => {
            link.addEventListener("click", () => {
                requestAnimationFrame(() => setNavOpen(false));
            });
        });
    }

    document.addEventListener("keydown", (e) => {
        if (!isDrawerOpen()) return;
        if (e.key === "Escape") {
            e.preventDefault();
            setNavOpen(false);
        }
    });

    const desktopQuery = window.matchMedia("(min-width: 981px)");
    const handleBreakpoint = (e) => {
        if (e.matches && isDrawerOpen()) setNavOpen(false);
    };
    if (desktopQuery.addEventListener) {
        desktopQuery.addEventListener("change", handleBreakpoint);
    } else if (desktopQuery.addListener) {
        desktopQuery.addListener(handleBreakpoint);
    }
}

// Chime Synthesis (Web Audio API)
const playChime = (type = 'incoming') => {
    try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.connect(gain);
        gain.connect(ctx.destination);
        
        if (type === 'incoming') {
            osc.frequency.setValueAtTime(587.33, ctx.currentTime);
            osc.frequency.setValueAtTime(880, ctx.currentTime + 0.1);
            gain.gain.setValueAtTime(0.08, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.4);
            osc.start();
            osc.stop(ctx.currentTime + 0.4);
        } else if (type === 'outgoing') {
            osc.frequency.setValueAtTime(440, ctx.currentTime);
            osc.frequency.setValueAtTime(554.37, ctx.currentTime + 0.08);
            gain.gain.setValueAtTime(0.06, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.3);
            osc.start();
            osc.stop(ctx.currentTime + 0.3);
        }
    } catch (e) {
        console.warn('Web Audio synthesis chime not supported or allowed.', e);
    }
};

// 2. Chat Render & Auto-replies
const renderMessages = (items) => {
    if (!messageList) return;
    
    const typingIndicator = messageList.querySelector(".message.compact");
    
    messageList.replaceChildren();

    items.forEach(([senderName, text, type]) => {
        const message = document.createElement("div");
        const sender = document.createElement("span");
        const body = document.createElement("p");

        message.className = `message ${type}`;
        sender.className = "sender";
        sender.textContent = senderName;
        body.textContent = text;
        message.append(sender, body);
        messageList.append(message);
    });

    if (typingIndicator) {
        messageList.append(typingIndicator);
    } else {
        const indicator = document.createElement("div");
        indicator.className = "message incoming compact";
        indicator.style.display = "none";
        for (let i = 0; i < 3; i++) {
            const dot = document.createElement("span");
            dot.className = "typing-dot";
            indicator.append(dot);
        }
        messageList.append(indicator);
    }
    
    scrollToBottom();
};

const scrollToBottom = () => {
    if (messageList) {
        messageList.scrollTop = messageList.scrollHeight;
    }
};

const setConversation = ({ title, status, input, messages }) => {
    if (conversationTitle) conversationTitle.textContent = title;
    if (conversationStatus) conversationStatus.textContent = status;
    if (messageInput) messageInput.value = input;
    
    renderMessages(messages);
};

// Active channel clicks
channels.forEach((channel) => {
    channel.addEventListener("click", () => {
        channels.forEach((item) => item.classList.remove("active"));
        channel.classList.add("active");

        setConversation({
            title: channel.dataset.channel,
            status: channel.dataset.status,
            input: channel.dataset.preview,
            messages: [
                [channel.dataset.channel, channel.dataset.preview, "incoming"],
                ["You", "Got it. I am checking this thread now.", "outgoing"]
            ]
        });
    });
});

// Mode switch click handler
heroModes.forEach((button) => {
    button.addEventListener("click", () => {
        heroModes.forEach((item) => item.classList.remove("active"));
        button.classList.add("active");

        const mode = button.dataset.mode;
        setConversation(modeMessages[mode]);
    });
});

heroStats.forEach((stat) => {
    stat.addEventListener("click", () => {
        heroStats.forEach((item) => item.classList.remove("active"));
        stat.classList.add("active");
    });
});

reactionButtons.forEach((button) => {
    button.addEventListener("click", () => {
        const count = button.querySelector("span");
        button.classList.toggle("active");
        if (count && button.classList.contains("active")) {
            count.textContent = Number(count.textContent) + 1;
            playChime('outgoing');
        } else if (count) {
            count.textContent = Number(count.textContent) - 1;
        }
    });
});

sidebarActions.forEach((button) => {
    button.addEventListener("click", () => {
        sidebarActions.forEach((item) => item.classList.remove("active"));
        button.classList.add("active");
    });
});

// Autoreply triggering logic
const triggerAutoReply = (userText) => {
    const text = userText.toLowerCase();
    let replyText = "Interesting! Let me sync with the engineering team and reply back.";
    
    if (text.includes("pricing") || text.includes("price") || text.includes("cost") || text.includes("sub")) {
        replyText = "Our pricing starts at $0 for small groups, $8/mo for Pro, and $16/mo for Business. Scroll down to Subscriptions to check details!";
    } else if (text.includes("video") || text.includes("call") || text.includes("phone")) {
        replyText = "I'm down! Try clicking the voice or video call icons in our chat header to test the high-fidelity connection interface.";
    } else if (text.includes("help") || text.includes("support")) {
        replyText = "For immediate customer help, you can query our Support team or visit the Help center link in our navigation links.";
    } else if (text.includes("hello") || text.includes("hi") || text.includes("hey")) {
        replyText = "Hello! Thanks for checking out FutureToppers. Let me know if you want to explore the customizable themes or plans!";
    } else if (text.includes("design") || text.includes("logo") || text.includes("prototype")) {
        replyText = "That prototype layout is looking amazing. Let's make sure the client reviews it today.";
    }

    const typingIndicator = messageList.querySelector(".message.compact");
    
    setTimeout(() => {
        if (typingIndicator) {
            typingIndicator.style.display = "flex";
            scrollToBottom();
        }
        
        setTimeout(() => {
            if (typingIndicator) {
                typingIndicator.style.display = "none";
            }
            
            const replyMsg = document.createElement("div");
            replyMsg.className = "message incoming";
            
            const sender = document.createElement("span");
            sender.className = "sender";
            sender.textContent = conversationTitle ? conversationTitle.textContent : "Maya";
            
            const body = document.createElement("p");
            body.textContent = replyText;
            
            replyMsg.append(sender, body);
            
            if (typingIndicator) {
                messageList.insertBefore(replyMsg, typingIndicator);
            } else {
                messageList.append(replyMsg);
            }
            
            scrollToBottom();
            playChime('incoming');
        }, 1500);
    }, 800);
};

// Message submit
if (messageForm && messageInput && messageList) {
    messageForm.addEventListener("submit", (event) => {
        event.preventDefault();

        const text = messageInput.value.trim();
        if (!text) return;

        const typingIndicator = messageList.querySelector(".message.compact");
        const message = document.createElement("div");
        const sender = document.createElement("span");
        const body = document.createElement("p");

        message.className = "message outgoing";
        sender.className = "sender";
        sender.textContent = "You";
        body.textContent = text;
        message.append(sender, body);
        
        if (typingIndicator) {
            messageList.insertBefore(message, typingIndicator);
        } else {
            messageList.append(message);
        }

        messageInput.value = "";
        scrollToBottom();
        playChime('outgoing');
        
        triggerAutoReply(text);
    });
}

// 3. Phone & Video Call Overlays logic
const callOverlay = document.getElementById("callOverlay");
const callTitle = document.getElementById("callTitle");
const callStatus = document.getElementById("callStatus");
const callAvatar = document.getElementById("callAvatar");
const callVoiceWaves = document.getElementById("callVoiceWaves");
const callVideoGrid = document.getElementById("callVideoGrid");
const remoteFeedLabel = document.getElementById("remoteFeedLabel");
const remoteFeedAvatar = document.getElementById("remoteFeedAvatar");

const btnStartVoice = document.getElementById("btnStartVoice");
const btnStartVideo = document.getElementById("btnStartVideo");
const btnEndCall = document.getElementById("btnEndCall");
const btnMute = document.getElementById("btnMute");
const btnCamera = document.getElementById("btnCamera");

let callTimer = null;
let secondsElapsed = 0;

const formatTime = (secs) => {
    const mins = Math.floor(secs / 60).toString().padStart(2, '0');
    const remaining = (secs % 60).toString().padStart(2, '0');
    return `${mins}:${remaining}`;
};

const startCallTimer = () => {
    secondsElapsed = 0;
    callTimer = setInterval(() => {
        secondsElapsed++;
        const currentMode = callVideoGrid.style.display === "grid" ? "Video Call" : "Voice Call";
        callStatus.textContent = `${currentMode} - ${formatTime(secondsElapsed)}`;
    }, 1000);
};

const stopCallTimer = () => {
    if (callTimer) {
        clearInterval(callTimer);
        callTimer = null;
    }
};

const openCallOverlay = (isVideo) => {
    if (!callOverlay) return;
    
    const activeChannelTitle = conversationTitle ? conversationTitle.textContent : "App Team";
    const firstLetter = activeChannelTitle.charAt(0);
    
    callTitle.textContent = `${activeChannelTitle} Call`;
    callAvatar.textContent = firstLetter;
    
    if (remoteFeedLabel) remoteFeedLabel.textContent = activeChannelTitle === "Friends" ? "Riya" : (activeChannelTitle === "Support Desk" ? "Customer" : "Maya");
    if (remoteFeedAvatar) remoteFeedAvatar.textContent = firstLetter;

    callOverlay.style.display = "flex";
    callOverlay.style.opacity = 0;
    
    setTimeout(() => {
        callOverlay.style.opacity = 1;
    }, 50);

    playChime('outgoing');

    if (isVideo) {
        callVoiceWaves.style.display = "none";
        callVideoGrid.style.display = "grid";
        btnCamera.style.display = "inline-grid";
        btnCamera.classList.remove("active");
        callStatus.textContent = "Connecting video stream...";
        
        setTimeout(() => {
            playChime('incoming');
            startCallTimer();
        }, 1500);
    } else {
        callVoiceWaves.style.display = "flex";
        callVideoGrid.style.display = "none";
        btnCamera.style.display = "none";
        callStatus.textContent = "Connecting voice room...";
        
        setTimeout(() => {
            playChime('incoming');
            startCallTimer();
        }, 1500);
    }
};

if (btnStartVoice) {
    btnStartVoice.addEventListener("click", () => openCallOverlay(false));
}
if (btnStartVideo) {
    btnStartVideo.addEventListener("click", () => openCallOverlay(true));
}
if (btnEndCall) {
    btnEndCall.addEventListener("click", () => {
        stopCallTimer();
        if (callOverlay) {
            callOverlay.style.opacity = 0;
            setTimeout(() => {
                callOverlay.style.display = "none";
            }, 300);
        }
        playChime('outgoing');
    });
}
if (btnMute) {
    btnMute.addEventListener("click", () => {
        btnMute.classList.toggle("active");
        const icon = btnMute.querySelector("i");
        if (btnMute.classList.contains("active")) {
            icon.className = "fa-solid fa-microphone-slash";
        } else {
            icon.className = "fa-solid fa-microphone";
        }
        playChime('outgoing');
    });
}
if (btnCamera) {
    btnCamera.addEventListener("click", () => {
        btnCamera.classList.toggle("active");
        const icon = btnCamera.querySelector("i");
        const localFeed = document.querySelector(".local-feed");
        
        if (btnCamera.classList.contains("active")) {
            icon.className = "fa-solid fa-video-slash";
            if (localFeed) localFeed.style.opacity = 0.4;
        } else {
            icon.className = "fa-solid fa-video";
            if (localFeed) localFeed.style.opacity = 1;
        }
        playChime('outgoing');
    });
}

// 4. File Attachment Upload Simulation
const attachBtn = document.getElementById("attachBtn");
const attachMenu = document.getElementById("attachMenu");

if (attachBtn && attachMenu) {
    attachBtn.addEventListener("click", (e) => {
        e.stopPropagation();
        attachMenu.style.display = attachMenu.style.display === "none" ? "flex" : "none";
    });
    
    document.addEventListener("click", () => {
        attachMenu.style.display = "none";
    });
    
    const menuButtons = attachMenu.querySelectorAll("button");
    menuButtons.forEach(btn => {
        btn.addEventListener("click", (e) => {
            e.stopPropagation();
            attachMenu.style.display = "none";
            
            const fileName = btn.dataset.fileName;
            const fileType = btn.dataset.fileType;
            let iconClass = "fa-file-image";
            
            if (fileType === "doc") iconClass = "fa-file-pdf";
            if (fileType === "code") iconClass = "fa-file-code";
            
            simulateFileUpload(fileName, iconClass);
        });
    });
}

const simulateFileUpload = (fileName, iconClass) => {
    if (!messageList) return;
    
    const typingIndicator = messageList.querySelector(".message.compact");
    
    const bubble = document.createElement("div");
    bubble.className = "message outgoing file-bubble";
    
    const icon = document.createElement("div");
    icon.className = "file-bubble-icon";
    icon.innerHTML = `<i class="fa-solid ${iconClass}"></i>`;
    
    const details = document.createElement("div");
    details.className = "file-bubble-details";
    
    const name = document.createElement("span");
    name.className = "file-bubble-name";
    name.textContent = fileName;
    
    const meta = document.createElement("span");
    meta.className = "file-bubble-meta";
    meta.textContent = "Uploading 0%...";
    
    const progressContainer = document.createElement("div");
    progressContainer.className = "file-progress-container";
    
    const progressBar = document.createElement("div");
    progressBar.className = "file-progress-bar";
    
    const progressFill = document.createElement("div");
    progressFill.className = "file-progress-fill";
    
    progressBar.append(progressFill);
    progressContainer.append(progressBar);
    details.append(name, meta, progressContainer);
    bubble.append(icon, details);
    
    if (typingIndicator) {
        messageList.insertBefore(bubble, typingIndicator);
    } else {
        messageList.append(bubble);
    }
    
    scrollToBottom();
    playChime('outgoing');
    
    let percentage = 0;
    const interval = setInterval(() => {
        percentage += 10;
        progressFill.style.width = `${percentage}%`;
        meta.textContent = `Uploading ${percentage}%...`;
        
        if (percentage >= 100) {
            clearInterval(interval);
            meta.textContent = "1.2 MB • Upload Complete";
            progressContainer.remove();
            playChime('incoming');
            
            setTimeout(() => {
                const receiverName = conversationTitle ? conversationTitle.textContent : "Maya";
                const textReply = `Received file "${fileName}". Let me open it and review the code/specifications!`;
                
                if (typingIndicator) {
                    typingIndicator.style.display = "flex";
                    scrollToBottom();
                }
                
                setTimeout(() => {
                    if (typingIndicator) typingIndicator.style.display = "none";
                    
                    const replyMsg = document.createElement("div");
                    replyMsg.className = "message incoming";
                    const sender = document.createElement("span");
                    sender.className = "sender";
                    sender.textContent = receiverName;
                    const body = document.createElement("p");
                    body.textContent = textReply;
                    replyMsg.append(sender, body);
                    
                    if (typingIndicator) {
                        messageList.insertBefore(replyMsg, typingIndicator);
                    } else {
                        messageList.append(replyMsg);
                    }
                    
                    scrollToBottom();
                    playChime('incoming');
                }, 1500);
            }, 1000);
        }
    }, 150);
};

// 5. Pricing Toggle Switch
const pricingPeriodCheckbox = document.getElementById("pricingPeriodCheckbox");
const labelMonthly = document.getElementById("labelMonthly");
const labelAnnually = document.getElementById("labelAnnually");
const cardPrices = document.querySelectorAll(".price");

const updatePricingValues = () => {
    const isAnnual = pricingPeriodCheckbox ? pricingPeriodCheckbox.checked : false;
    
    if (labelMonthly) labelMonthly.classList.toggle("active", !isAnnual);
    if (labelAnnually) labelAnnually.classList.toggle("active", isAnnual);
    
    cardPrices.forEach(priceEl => {
        const monthly = priceEl.dataset.priceMonthly;
        const annual = priceEl.dataset.priceAnnual;
        const targetPrice = isAnnual ? annual : monthly;
        
        priceEl.style.transform = "scale(0.8)";
        priceEl.style.opacity = "0.3";
        
        setTimeout(() => {
            priceEl.innerHTML = `$${targetPrice}<span>/mo</span>`;
            priceEl.style.transform = "scale(1)";
            priceEl.style.opacity = "1";
        }, 150);
    });
    
    if (studyHoursRange) {
        recalculateStudyPlan();
    }
};

if (pricingPeriodCheckbox) {
    pricingPeriodCheckbox.addEventListener("change", updatePricingValues);
}

// 6. Study Plan Calculator
const studyHoursRange = document.getElementById("studyHoursRange");
const studyHoursDisplay = document.getElementById("studyHoursDisplay");
const planPomodoro = document.getElementById("planPomodoro");
const planXP = document.getElementById("planXP");
const planGoal = document.getElementById("planGoal");

const recalculateStudyPlan = () => {
    if (!studyHoursRange) return;
    const hours = parseInt(studyHoursRange.value, 10);
    if (Number.isNaN(hours)) return;

    studyHoursDisplay.textContent = `${hours} hr${hours === 1 ? '' : 's'} / day`;

    // 25-min focus + 5-min break ≈ 30 min per pomodoro
    const sessions = Math.max(1, Math.round((hours * 60) / 30));
    const xpTarget = sessions * 50;
    const weeklyGoal = sessions * 7;

    planPomodoro.textContent = `${sessions} × 25 min`;
    planXP.textContent = `${xpTarget} XP`;
    planGoal.textContent = `${weeklyGoal} sessions`;
};

if (studyHoursRange) {
    studyHoursRange.addEventListener("input", recalculateStudyPlan);
    recalculateStudyPlan();
}

// 7. Cryptographic Encryption Playground Logic
const decryptInput = document.getElementById("decryptInput");
const decryptOutput = document.getElementById("decryptOutput");
const cipherDisplay = document.getElementById("cipherDisplay");
const tunnelLockIcon = document.getElementById("tunnelLockIcon");
const cryptoTunnel = document.getElementById("cryptoTunnel");

const performEncryptionSim = () => {
    if (!decryptInput) return;
    const text = decryptInput.value;
    
    let hexCipher = "";
    for (let i = 0; i < text.length; i++) {
        hexCipher += text.charCodeAt(i).toString(16).toUpperCase().padStart(2, '0');
    }
    
    if (hexCipher === "") {
        hexCipher = "00";
    }
    
    cipherDisplay.textContent = hexCipher;
    decryptOutput.textContent = text ? text : "(Empty Message)";
    
    const bits = cryptoTunnel ? cryptoTunnel.querySelectorAll(".stream-bit") : [];
    bits.forEach(bit => {
        bit.style.borderColor = "var(--coral)";
        bit.style.color = "var(--coral)";
        setTimeout(() => {
            bit.style.borderColor = "var(--violet)";
            bit.style.color = "var(--violet)";
        }, 300);
    });
    
    if (tunnelLockIcon) {
        if (text.length > 0) {
            tunnelLockIcon.className = "fa-solid fa-lock text-green";
        } else {
            tunnelLockIcon.className = "fa-solid fa-lock-open text-teal";
        }
    }
};

if (decryptInput) {
    decryptInput.addEventListener("input", performEncryptionSim);
    performEncryptionSim();
}

// Initial chat scroll setup
scrollToBottom();

// ==========================================================================
// 8. Hero Floating Badges Proximity Displacement (Spring Physics Parallax)
// ==========================================================================
const floatingWidgets = document.querySelectorAll(".hero-bubble, .hero-chart, .hero-widget");
const heroSection = document.querySelector(".hero");

const widgetData = Array.from(floatingWidgets).map(el => ({
    element: el,
    x: 0,
    y: 0,
    targetX: 0,
    targetY: 0
}));

if (heroSection && widgetData.length > 0) {
    heroSection.addEventListener("mousemove", (e) => {
        const heroRect = heroSection.getBoundingClientRect();
        const mouseX = e.clientX - heroRect.left;
        const mouseY = e.clientY - heroRect.top;

        widgetData.forEach(data => {
            const rect = data.element.getBoundingClientRect();
            const elementX = rect.left - heroRect.left + rect.width / 2;
            const elementY = rect.top - heroRect.top + rect.height / 2;

            const dx = elementX - mouseX;
            const dy = elementY - mouseY;
            const dist = Math.sqrt(dx * dx + dy * dy);

            if (dist < 220) {
                const force = (220 - dist) / 220;
                const pushAngle = Math.atan2(dy, dx);
                const pushX = Math.cos(pushAngle) * force * 22;
                const pushY = Math.sin(pushAngle) * force * 22;

                data.targetX = pushX;
                data.targetY = pushY - 6;
            } else {
                data.targetX = 0;
                data.targetY = 0;
            }
        });
    });

    heroSection.addEventListener("mouseleave", () => {
        widgetData.forEach(data => {
            data.targetX = 0;
            data.targetY = 0;
        });
    });

    const updateWidgetPhysics = () => {
        widgetData.forEach(data => {
            data.x += (data.targetX - data.x) * 0.08;
            data.y += (data.targetY - data.y) * 0.08;

            data.element.style.translate = `${data.x}px ${data.y}px`;
        });
        requestAnimationFrame(updateWidgetPhysics);
    };
    updateWidgetPhysics();
}

// Mark the showcase as "loaded" so the entry animations play after first paint
(() => {
    const showcase = document.querySelector('.chat-showcase');
    if (!showcase) return;
    requestAnimationFrame(() => requestAnimationFrame(() => {
        showcase.classList.add('is-loaded');
    }));
})();

// Re-trigger floating-widget entry animation whenever the user switches the chat-preview mode
(() => {
    const showcase = document.querySelector('.chat-showcase');
    const heroModes = document.querySelectorAll('.hero-mode');
    if (!showcase || heroModes.length === 0) return;

    heroModes.forEach((mode) => {
        mode.addEventListener('click', () => {
            showcase.classList.add('swap');
            setTimeout(() => {
                showcase.classList.remove('swap');
                showcase.classList.add('is-loaded');
            }, 180);
        });
    });
})();

// ==========================================================================
// 10. Scroll Reveal Animations (IntersectionObserver)
// ==========================================================================
(() => {
    const reveals = document.querySelectorAll('.reveal');
    const grids = document.querySelectorAll('.feature-grid, .pricing-grid');

    if (!('IntersectionObserver' in window)) {
        reveals.forEach(el => el.classList.add('is-visible'));
        grids.forEach(el => el.classList.add('is-visible'));
        return;
    }

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('is-visible');
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });

    reveals.forEach(el => observer.observe(el));
    grids.forEach(el => observer.observe(el));
})();

// ==========================================================================
// 11. Navbar shadow depth on scroll
// ==========================================================================
(() => {
    const header = document.querySelector('.site-header');
    if (!header) return;

    const onScroll = () => {
        header.classList.toggle('is-scrolled', window.scrollY > 8);
    };

    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
})();

// ==========================================================================
// 12. Animated counters on scroll
// ==========================================================================
(() => {
    const counters = document.querySelectorAll('[data-counter]');
    if (!counters.length) return;

    const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    const animateCount = (el) => {
        const target = parseInt(el.dataset.counter, 10);
        if (Number.isNaN(target)) return;
        if (prefersReduced) {
            el.textContent = target.toLocaleString();
            return;
        }
        const duration = 1400;
        const start = performance.now();

        const tick = (now) => {
            const elapsed = now - start;
            const progress = Math.min(elapsed / duration, 1);
            const eased = 1 - Math.pow(1 - progress, 3);
            const value = Math.round(target * eased);
            el.textContent = value.toLocaleString();
            if (progress < 1) {
                requestAnimationFrame(tick);
            } else {
                el.textContent = target.toLocaleString();
            }
        };
        requestAnimationFrame(tick);
    };

    if (!('IntersectionObserver' in window)) {
        counters.forEach(animateCount);
        return;
    }

    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (entry.isIntersecting) {
                animateCount(entry.target);
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.5 });

    counters.forEach((c) => observer.observe(c));
})();

// ==========================================================================
// 13. Feature privacy toggles interaction
// ==========================================================================
(() => {
    const toggles = document.querySelectorAll('.feature-toggle');
    toggles.forEach((label) => {
        const input = label.querySelector('input');
        if (!input) return;
        label.addEventListener('click', (e) => {
            if (e.target !== input) {
                e.preventDefault();
                input.checked = !input.checked;
                input.dispatchEvent(new Event('change'));
            }
        });
    });
})();

// ==========================================================================
// 14. Typewriter effect for hero headline
// ==========================================================================
(() => {
    const headline = document.querySelector('.hero h1.typewriter');
    if (!headline) return;

    const textEl = headline.querySelector('.typewriter-text');
    const caret  = headline.querySelector('.typewriter-caret');
    if (!textEl || !caret) return;

    const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // EduMate-themed rotating phrases
    const phrases = [
        'CBSE, ICSE & more.',
        'flashcards that stick.',
        'focus timers that work.',
        'group study rooms.',
        'past papers & quizzes.',
        'your board, your pace.',
    ];

    if (prefersReduced) {
        textEl.textContent = phrases[0];
        return;
    }

    // Lock the h1 height to the tallest phrase so layout never jumps
    const longest = phrases.reduce((a, b) => a.length >= b.length ? a : b);
    const ghost = document.createElement('span');
    ghost.setAttribute('aria-hidden', 'true');
    ghost.style.cssText = 'visibility:hidden;position:absolute;pointer-events:none;white-space:nowrap;';
    ghost.textContent = longest;
    textEl.parentNode.insertBefore(ghost, textEl);
    const lockH = () => {
        const h = headline.getBoundingClientRect().height;
        if (h > 0) headline.style.minHeight = h + 'px';
    };
    requestAnimationFrame(() => { lockH(); ghost.remove(); });

    let phraseIdx = 0, charIdx = 0, deleting = false;
    const TYPE_MS   = 60;
    const DEL_MS    = 30;
    const PAUSE_END = 1800;
    const PAUSE_DEL = 350;

    const tick = () => {
        const phrase = phrases[phraseIdx];
        if (!deleting) {
            charIdx++;
            textEl.textContent = phrase.slice(0, charIdx);
            if (charIdx >= phrase.length) {
                deleting = true;
                return setTimeout(tick, PAUSE_END);
            }
            return setTimeout(tick, TYPE_MS + Math.random() * 40);
        }
        charIdx--;
        textEl.textContent = phrase.slice(0, charIdx);
        if (charIdx <= 0) {
            deleting = false;
            phraseIdx = (phraseIdx + 1) % phrases.length;
            return setTimeout(tick, PAUSE_DEL);
        }
        setTimeout(tick, DEL_MS);
    };

    // Start immediately — hero is always visible on load
    setTimeout(tick, 600);
})();

// ==========================================================================
// 15. Page loader — FIXED: properly hides the loader
// ==========================================================================
(() => {
    const loader = document.getElementById('pageLoader');
    if (!loader) return;

    let loaderHidden = false;

    const hideLoader = () => {
        if (loaderHidden) return;
        loaderHidden = true;
        
        // Add the hidden class to trigger fade out
        loader.classList.add('is-hidden');
        
        // Remove from DOM after transition completes
        setTimeout(() => {
            if (loader.parentNode) {
                loader.parentNode.removeChild(loader);
            }
        }, 800);
    };

    // If the page is already loaded, hide immediately
    if (document.readyState === 'complete') {
        setTimeout(hideLoader, 400);
        return;
    }

    // Hide on load
    window.addEventListener('load', () => {
        setTimeout(hideLoader, 400);
    }, { once: true });

    // Also hide if DOM is ready but images might be slow
    document.addEventListener('DOMContentLoaded', () => {
        setTimeout(() => {
            if (!loaderHidden) {
                hideLoader();
            }
        }, 2000);
    }, { once: true });

    // Hard safety net — never block the page for more than 4 seconds
    setTimeout(hideLoader, 4000);

    // If the user clicks anywhere on the loader, dismiss it
    loader.addEventListener('click', hideLoader);
})();

// ==========================================================================
// 16. FAQ — searchable + category-filtered accordion
// ==========================================================================
(() => {
    const input = document.querySelector('[data-faq-search]');
    const clearBtns = document.querySelectorAll('[data-faq-clear]');
    const tagButtons = document.querySelectorAll('[data-faq-tag]');
    const list = document.querySelector('[data-faq-list]');
    const emptyState = document.querySelector('[data-faq-empty]');
    const items = list ? Array.from(list.querySelectorAll('[data-faq-item]')) : [];

    if (!list || items.length === 0) return;

    const state = {
        query: '',
        tag: 'all'
    };

    const escapeRegex = (s) => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

    const clearHighlights = (el) => {
        const marks = el.querySelectorAll('.faq-mark');
        marks.forEach((m) => {
            const parent = m.parentNode;
            if (!parent) return;
            parent.replaceChild(document.createTextNode(m.textContent), m);
            parent.normalize();
        });
    };

    const highlight = (el, term) => {
        if (!term) return;
        const regex = new RegExp(`(${escapeRegex(term)})`, 'gi');

        const walk = (node) => {
            if (node.nodeType === Node.TEXT_NODE) {
                const text = node.nodeValue;
                if (!regex.test(text)) {
                    regex.lastIndex = 0;
                    return;
                }
                regex.lastIndex = 0;
                const fragment = document.createDocumentFragment();
                let lastIdx = 0;
                let match;
                while ((match = regex.exec(text)) !== null) {
                    const before = text.slice(lastIdx, match.index);
                    if (before) fragment.appendChild(document.createTextNode(before));
                    const mark = document.createElement('mark');
                    mark.className = 'faq-mark';
                    mark.textContent = match[0];
                    fragment.appendChild(mark);
                    lastIdx = match.index + match[0].length;
                }
                const after = text.slice(lastIdx);
                if (after) fragment.appendChild(document.createTextNode(after));
                node.parentNode.replaceChild(fragment, node);
            } else if (node.nodeType === Node.ELEMENT_NODE) {
                if (node.classList && node.classList.contains('faq-mark')) return;
                Array.from(node.childNodes).forEach(walk);
            }
        };

        Array.from(el.childNodes).forEach(walk);
    };

    const setClearVisibility = () => {
        if (!input) return;
        const hasValue = input.value.trim().length > 0;
        clearBtns.forEach((btn) => {
            btn.classList.toggle('is-visible', hasValue);
        });
    };

    const matches = (item) => {
        const tags = (item.dataset.tags || '').toLowerCase().split(/\s+/).filter(Boolean);
        if (state.tag !== 'all' && !tags.includes(state.tag)) return false;

        if (!state.query) return true;

        const questionEl = item.querySelector('.faq-q');
        const answerEl = item.querySelector('.faq-a');
        const haystack = `${questionEl ? questionEl.textContent : ''} ${answerEl ? answerEl.textContent : ''}`.toLowerCase();
        return haystack.includes(state.query);
    };

    const applyFilter = () => {
        let visibleCount = 0;
        items.forEach((item) => {
            const matched = matches(item);
            item.classList.toggle('is-hidden', !matched);
            if (matched) visibleCount += 1;

            clearHighlights(item);
            if (matched && state.query) {
                const qEl = item.querySelector('.faq-q');
                const aEl = item.querySelector('.faq-a');
                if (qEl) highlight(qEl, state.query);
                if (aEl) highlight(aEl, state.query);
            }
        });

        if (emptyState) {
            const hasQuery = state.query.length > 0;
            const noMatches = visibleCount === 0;
            emptyState.hidden = !(hasQuery && noMatches);
        }
    };

    if (input) {
        input.addEventListener('input', () => {
            state.query = input.value.trim().toLowerCase();
            setClearVisibility();
            applyFilter();
        });

        input.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                input.value = '';
                state.query = '';
                setClearVisibility();
                applyFilter();
            }
        });
    }

    clearBtns.forEach((btn) => {
        btn.addEventListener('click', () => {
            if (input) {
                input.value = '';
                input.focus();
            }
            state.query = '';
            setClearVisibility();
            applyFilter();
        });
    });

    tagButtons.forEach((btn) => {
        btn.addEventListener('click', () => {
            tagButtons.forEach((b) => b.classList.remove('active'));
            btn.classList.add('active');
            state.tag = btn.dataset.faqTag || 'all';
            applyFilter();
        });
    });

    setClearVisibility();
})();

/* =========================================================
   Scroll-to-top button with progress ring
   ========================================================= */
(function initScrollTop() {
    const btn = document.getElementById('scrollTopBtn');
    if (!btn) return;

    const ring = btn.querySelector('.scroll-top-ring-progress');
    const SHOW_AFTER = window.innerHeight;

    const CIRC = 2 * Math.PI * 25;
    if (ring) {
        ring.style.strokeDasharray = String(CIRC);
        ring.style.strokeDashoffset = String(CIRC);
    }

    let ticking = false;

    function update() {
        const scrollTop = window.scrollY || document.documentElement.scrollTop || 0;
        const docHeight = Math.max(
            document.documentElement.scrollHeight,
            document.body.scrollHeight
        );
        const viewHeight = window.innerHeight;
        const maxScroll = Math.max(1, docHeight - viewHeight);
        const progress = Math.min(1, Math.max(0, scrollTop / maxScroll));
        const pct = Math.round(progress * 100);

        const shouldShow = scrollTop > SHOW_AFTER * 0.35 || progress > 0.05;
        btn.classList.toggle('is-visible', shouldShow);

        btn.classList.toggle('is-complete', progress >= 0.999);

        if (ring) {
            const offset = CIRC * (1 - progress);
            ring.style.strokeDashoffset = String(offset);
            ring.setAttribute(
                'stroke',
                progress >= 0.999 ? 'url(#scrollTopProgressGradientDone)' : 'url(#scrollTopProgressGradient)'
            );
        }

        const tmpl = btn.dataset.titleTemplate;
        if (tmpl) {
            btn.title = tmpl.replace('{pct}', String(pct));
        }

        ticking = false;
    }

    function onScroll() {
        if (!ticking) {
            window.requestAnimationFrame(update);
            ticking = true;
        }
    }

    function scrollToTop() {
        const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (prefersReduced || !('scrollBehavior' in document.documentElement.style)) {
            window.scrollTo({ top: 0, behavior: 'auto' });
        } else {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    }

    btn.addEventListener('click', () => {
        btn.classList.remove('is-rippling');
        void btn.offsetWidth;
        btn.classList.add('is-rippling');
        scrollToTop();
        setTimeout(() => btn.classList.remove('is-rippling'), 600);
    });

    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', update, { passive: true });

    update();
})();

/* =========================================================
   Pricing toggle (Monthly ↔ Annually)
   ========================================================= */
(function initPricingToggle() {
    const checkbox = document.getElementById('pricingPeriodCheckbox');
    const labelMonthly = document.getElementById('labelMonthly');
    const labelAnnually = document.getElementById('labelAnnually');
    const priceEls = document.querySelectorAll('.price[data-price-monthly][data-price-annual]');
    if (!checkbox || !priceEls.length) return;

    const STORAGE_KEY = 'FutureToppers-pricing-period';

    let periodIsAnnual = false;
    try {
        periodIsAnnual = localStorage.getItem(STORAGE_KEY) === 'annual';
    } catch (_) { }

    function formatPrice(n) {
        const num = Number(n);
        if (Number.isNaN(num)) return n;
        return Number.isInteger(num) ? String(num) : num.toFixed(2);
    }

    function setPeriod(isAnnual, animate = true) {
        periodIsAnnual = isAnnual;
        checkbox.checked = isAnnual;

        if (labelMonthly && labelAnnually) {
            labelMonthly.classList.toggle('active', !isAnnual);
            labelAnnually.classList.toggle('active', isAnnual);
        }

        priceEls.forEach(el => {
            let displayValue, suffix;
            if (isAnnual) {
                const monthlyAnnual = Number(el.dataset.priceAnnual);
                if (Number.isFinite(monthlyAnnual)) {
                    displayValue = formatPrice(monthlyAnnual * 12);
                    suffix = '/yr';
                } else {
                    displayValue = formatPrice(el.dataset.priceAnnual);
                    suffix = '/yr';
                }
            } else {
                displayValue = formatPrice(el.dataset.priceMonthly);
                suffix = '/mo';
            }

            if (animate) {
                el.style.opacity = '0';
                el.style.transform = 'scale(0.96)';
                setTimeout(() => {
                    el.firstChild.nodeValue = `$${displayValue}`;
                    const span = el.querySelector('span');
                    if (span) span.textContent = suffix;
                    el.style.opacity = '1';
                    el.style.transform = 'scale(1)';
                }, 120);
            } else {
                el.firstChild.nodeValue = `$${displayValue}`;
                const span = el.querySelector('span');
                if (span) span.textContent = suffix;
            }
        });

        try {
            localStorage.setItem(STORAGE_KEY, isAnnual ? 'annual' : 'monthly');
        } catch (_) { }
    }

    setPeriod(periodIsAnnual, false);

    checkbox.addEventListener('change', () => setPeriod(checkbox.checked, true));

    labelMonthly && labelMonthly.addEventListener('click', () => setPeriod(false, true));
    labelAnnually && labelAnnually.addEventListener('click', () => setPeriod(true, true));
})();

/* =========================================================
   Hero Study Widget — Tabs, Flashcard, Quiz, Pomodoro
   ========================================================= */
(() => {
    // --- Tab switching ---
    const tabs = document.querySelectorAll('.sw-tab');
    const panels = {
        flashcard: document.getElementById('sw-panel-flashcard'),
        quiz:      document.getElementById('sw-panel-quiz'),
        focus:     document.getElementById('sw-panel-focus'),
    };

    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            tabs.forEach(t => { t.classList.remove('active'); t.setAttribute('aria-selected', 'false'); });
            tab.classList.add('active');
            tab.setAttribute('aria-selected', 'true');
            Object.entries(panels).forEach(([key, panel]) => {
                if (!panel) return;
                panel.hidden = key !== tab.dataset.swTab;
            });
        });
    });

    // --- Flashcard ---
    const cards = [
        { q: "What is Newton's Second Law?",          a: "F = ma — Force equals mass times acceleration." },
        { q: "What does DNA stand for?",              a: "Deoxyribonucleic Acid — the molecule of heredity." },
        { q: "Who wrote the Indian Constitution?",    a: "Dr. B.R. Ambedkar chaired the drafting committee." },
        { q: "What is the value of π (pi)?",          a: "Approximately 3.14159 — ratio of circumference to diameter." },
        { q: "What is photosynthesis?",               a: "Plants convert sunlight + CO₂ + water into glucose + oxygen." },
    ];

    let cardIndex = 0;
    let isFlipped = false;

    const flashcard    = document.getElementById('swFlashcard');
    const fcQuestion   = document.getElementById('swFcQuestion');
    const fcAnswer     = document.getElementById('swFcAnswer');
    const fcCounter    = document.getElementById('swFcCounter');
    const fcProgressBar = document.getElementById('swFcProgressBar');
    const fcPrev       = document.getElementById('swFcPrev');
    const fcNext       = document.getElementById('swFcNext');
    const burstLayer   = document.getElementById('swBurstLayer');

    const loadCard = (idx) => {
        isFlipped = false;
        flashcard && flashcard.classList.remove('is-flipped');
        if (fcQuestion) fcQuestion.textContent = cards[idx].q;
        if (fcAnswer)   fcAnswer.textContent   = cards[idx].a;
        if (fcCounter)  fcCounter.textContent  = `${idx + 1} / ${cards.length}`;
        if (fcProgressBar) fcProgressBar.style.width = `${((idx + 1) / cards.length) * 100}%`;
    };

    const spawnBurst = (emoji) => {
        if (!burstLayer) return;
        const el = document.createElement('span');
        el.className = 'sw-burst-emoji';
        el.textContent = emoji;
        const x = 30 + Math.random() * 40;
        const y = 30 + Math.random() * 40;
        const dx = (Math.random() - 0.5) * 60;
        el.style.cssText = `left:${x}%;top:${y}%;--dx:${dx}px;`;
        burstLayer.appendChild(el);
        setTimeout(() => el.remove(), 1700);
    };

    if (flashcard) {
        flashcard.addEventListener('click', () => {
            isFlipped = !isFlipped;
            flashcard.classList.toggle('is-flipped', isFlipped);
            if (isFlipped) spawnBurst(['✨','🎉','💡','🔥','⭐'][Math.floor(Math.random() * 5)]);
        });
        flashcard.addEventListener('keydown', e => {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); flashcard.click(); }
        });
    }

    fcPrev && fcPrev.addEventListener('click', () => {
        cardIndex = (cardIndex - 1 + cards.length) % cards.length;
        loadCard(cardIndex);
    });
    fcNext && fcNext.addEventListener('click', () => {
        cardIndex = (cardIndex + 1) % cards.length;
        loadCard(cardIndex);
        spawnBurst('📚');
    });

    loadCard(0);

    // --- Quiz ---
    const quizData = [
        {
            q: "Which board follows the NCERT syllabus?",
            opts: ["ICSE", "CBSE", "WBBSE", "Maharashtra Board"],
            correct: 1,
            feedback: { correct: "✅ Correct! CBSE is aligned with NCERT.", wrong: "❌ Not quite — CBSE follows NCERT." }
        },
        {
            q: "Madhyamik is the Class 10 exam of which board?",
            opts: ["CBSE", "ICSE", "WBBSE", "Maharashtra Board"],
            correct: 2,
            feedback: { correct: "✅ Right! Madhyamik is WBBSE's Class 10 exam.", wrong: "❌ Madhyamik belongs to WBBSE." }
        },
        {
            q: "SSC and HSC exams are conducted by which board?",
            opts: ["CBSE", "ICSE", "WBBSE", "Maharashtra Board"],
            correct: 3,
            feedback: { correct: "✅ Correct! Maharashtra Board conducts SSC & HSC.", wrong: "❌ SSC/HSC are Maharashtra Board exams." }
        },
    ];

    let quizIdx = 0;
    let quizAnswered = false;

    const quizQ       = document.getElementById('swQuizQ');
    const quizOptions = document.getElementById('swQuizOptions');
    const quizFeedback = document.getElementById('swQuizFeedback');
    const quizNext    = document.getElementById('swQuizNext');

    const loadQuiz = (idx) => {
        quizAnswered = false;
        const data = quizData[idx % quizData.length];
        if (quizQ) quizQ.textContent = data.q;
        if (quizFeedback) { quizFeedback.hidden = true; quizFeedback.textContent = ''; quizFeedback.className = 'sw-quiz__feedback'; }
        if (quizNext) quizNext.hidden = true;
        if (quizOptions) {
            quizOptions.innerHTML = '';
            data.opts.forEach((opt, i) => {
                const btn = document.createElement('button');
                btn.className = 'sw-quiz__opt';
                btn.textContent = opt;
                btn.dataset.correct = String(i === data.correct);
                btn.addEventListener('click', () => {
                    if (quizAnswered) return;
                    quizAnswered = true;
                    const isCorrect = i === data.correct;
                    btn.classList.add(isCorrect ? 'is-correct' : 'is-wrong');
                    if (!isCorrect) {
                        quizOptions.querySelectorAll('.sw-quiz__opt')[data.correct].classList.add('is-correct');
                    }
                    quizOptions.querySelectorAll('.sw-quiz__opt').forEach(b => b.disabled = true);
                    if (quizFeedback) {
                        quizFeedback.textContent = isCorrect ? data.feedback.correct : data.feedback.wrong;
                        quizFeedback.className = `sw-quiz__feedback ${isCorrect ? 'correct' : 'wrong'}`;
                        quizFeedback.hidden = false;
                    }
                    if (quizNext) quizNext.hidden = false;
                    spawnBurst(isCorrect ? '🎉' : '💪');
                    // bump XP
                    if (isCorrect) bumpStat('swXpNum', 10);
                });
                quizOptions.appendChild(btn);
            });
        }
    };

    quizNext && quizNext.addEventListener('click', () => {
        quizIdx++;
        loadQuiz(quizIdx);
    });

    loadQuiz(0);

    // --- Pomodoro ---
    const FOCUS_SECS  = 25 * 60;
    const BREAK_SECS  = 5 * 60;
    const TOTAL_SESSIONS = 4;

    let pomSecs      = FOCUS_SECS;
    let pomRunning   = false;
    let pomInterval  = null;
    let pomSession   = 1;
    let pomIsBreak   = false;
    const CIRC       = 2 * Math.PI * 52; // r=52

    const pomTime    = document.getElementById('swPomTime');
    const pomLabel   = document.getElementById('swPomLabel');
    const pomProgress = document.getElementById('swPomProgress');
    const pomToggle  = document.getElementById('swPomToggle');
    const pomReset   = document.getElementById('swPomReset');
    const pomSessionDots = document.getElementById('swPomSessionDots');
    const pomSessionNum  = document.getElementById('swPomSessionNum');

    if (pomProgress) {
        pomProgress.style.strokeDasharray = String(CIRC);
        pomProgress.style.strokeDashoffset = '0';
    }

    const renderDots = () => {
        if (!pomSessionDots) return;
        pomSessionDots.innerHTML = '';
        for (let i = 1; i <= TOTAL_SESSIONS; i++) {
            const dot = document.createElement('span');
            dot.className = `sw-pom-dot${i < pomSession ? ' done' : ''}`;
            pomSessionDots.appendChild(dot);
        }
    };

    const formatPomTime = (s) => {
        const m = Math.floor(s / 60).toString().padStart(2, '0');
        const sec = (s % 60).toString().padStart(2, '0');
        return `${m}:${sec}`;
    };

    const updatePomUI = () => {
        if (pomTime) pomTime.textContent = formatPomTime(pomSecs);
        const total = pomIsBreak ? BREAK_SECS : FOCUS_SECS;
        const ratio = pomSecs / total;
        if (pomProgress) {
            pomProgress.style.strokeDashoffset = String(CIRC * (1 - ratio));
            pomProgress.style.stroke = pomIsBreak ? 'var(--teal)' : 'var(--coral)';
        }
        if (pomLabel) pomLabel.textContent = pomIsBreak ? 'Break' : 'Focus';
    };

    const stopPom = () => {
        pomRunning = false;
        clearInterval(pomInterval);
        if (pomToggle) pomToggle.innerHTML = '<i class="fa-solid fa-play"></i> Start';
    };

    const startPom = () => {
        pomRunning = true;
        if (pomToggle) pomToggle.innerHTML = '<i class="fa-solid fa-pause"></i> Pause';
        pomInterval = setInterval(() => {
            pomSecs--;
            updatePomUI();
            if (pomSecs <= 0) {
                stopPom();
                spawnBurst(pomIsBreak ? '☕' : '🍅');
                if (!pomIsBreak) {
                    bumpStat('swXpNum', 25);
                    pomSession = Math.min(pomSession + 1, TOTAL_SESSIONS + 1);
                    if (pomSessionNum) pomSessionNum.textContent = Math.min(pomSession, TOTAL_SESSIONS);
                    renderDots();
                }
                pomIsBreak = !pomIsBreak;
                pomSecs = pomIsBreak ? BREAK_SECS : FOCUS_SECS;
                updatePomUI();
            }
        }, 1000);
    };

    pomToggle && pomToggle.addEventListener('click', () => {
        pomRunning ? stopPom() : startPom();
    });

    pomReset && pomReset.addEventListener('click', () => {
        stopPom();
        pomSecs = FOCUS_SECS;
        pomIsBreak = false;
        pomSession = 1;
        if (pomSessionNum) pomSessionNum.textContent = '1';
        renderDots();
        updatePomUI();
    });

    renderDots();
    updatePomUI();

    // --- Stat bump helper ---
    const bumpStat = (id, amount) => {
        const el = document.getElementById(id);
        if (!el) return;
        const current = parseInt(el.textContent.replace(/,/g, ''), 10) || 0;
        const target = current + amount;
        el.style.transform = 'scale(1.3)';
        el.style.color = 'var(--coral)';
        setTimeout(() => {
            el.textContent = target.toLocaleString();
            el.style.transform = 'scale(1)';
            el.style.color = '';
        }, 200);
    };

    // Bump cards counter when navigating flashcards
    fcNext && fcNext.addEventListener('click', () => bumpStat('swCardsNum', 1));
})();
