/**
 * EduMate Vendor Module - Voice Recognition Engine
 * Uses Sleek Toast Notifications instead of Native Browser Alerts
 */
(function(window) {
    'use strict';

    function notify(msg, type = 'info', title = '') {
        if (typeof window.showToast === 'function') {
            window.showToast(msg, type, title);
        } else {
            console.warn(`[${type.toUpperCase()}] ${title ? title + ': ' : ''}${msg}`);
        }
    }

    class VoiceRecognitionEngine {
        constructor(options = {}) {
            this.lang = options.lang || (navigator.language || 'en-US');
            
            this.SpeechRecognition = window.SpeechRecognition || 
                                     window.webkitSpeechRecognition || 
                                     window.mozSpeechRecognition || 
                                     window.msSpeechRecognition;
                                      
            this.supported = !!this.SpeechRecognition;
            this.isListening = false;
            this.activeRecognition = null;

            // Audio Visualizer states
            this.audioCtx = null;
            this.analyser = null;
            this.mediaStream = null;
            this.animFrameId = null;
        }

        isSupported() {
            return this.supported;
        }

        attach({ button, textarea, icon, indicator, onStart, onEnd, onError }) {
            if (!button || !textarea) return;

            const stopBtn = document.getElementById('broad-voice-stop-btn');
            if (stopBtn) {
                stopBtn.addEventListener('click', () => {
                    this.stop(button, icon, indicator);
                });
            }

            button.addEventListener('click', () => {
                const isSecure = window.isSecureContext || 
                                 location.protocol === 'https:' || 
                                 location.hostname === 'localhost' || 
                                 location.hostname === '127.0.0.1';
                                 
                if (!isSecure) {
                    notify('Voice recognition requires HTTPS or localhost. Please open EduMate via http://localhost/ or https://', 'warning', 'Security Notice');
                    return;
                }

                if (!this.supported) {
                    notify('Voice recognition is not supported in this browser. Please use Google Chrome, Microsoft Edge, or Safari.', 'error', 'Not Supported');
                    return;
                }

                if (this.isListening) {
                    this.stop(button, icon, indicator);
                    return;
                }

                this.start({ button, textarea, icon, indicator, onStart, onEnd, onError });
            });
        }

        start({ button, textarea, icon, indicator, onStart, onEnd, onError }) {
            try {
                if (this.activeRecognition) {
                    try { this.activeRecognition.abort(); } catch(e) {}
                }

                const recognition = new this.SpeechRecognition();
                recognition.lang = this.lang;
                recognition.continuous = false;
                recognition.interimResults = true;

                const initialText = textarea.value ? textarea.value + (textarea.value.endsWith(' ') ? '' : ' ') : '';

                recognition.onstart = () => {
                    this.isListening = true;
                    if (button) button.classList.add('listening');
                    if (icon) {
                        icon.setAttribute('name', 'mic');
                    }

                    const broadContainer = document.getElementById('broad-voice-container');
                    const waveTrack = document.getElementById('broad-wave-bars');

                    if (broadContainer) {
                        broadContainer.style.display = 'flex';
                    }

                    if (waveTrack) {
                        let barsHtml = '';
                        const barCount = 20;
                        for (let i = 0; i < barCount; i++) {
                            const delay = (Math.sin(i / 2) * 0.3 + 0.3).toFixed(2);
                            barsHtml += `<span class="broad-wave-bar" style="animation-delay: ${delay}s;"></span>`;
                        }
                        waveTrack.innerHTML = barsHtml;
                    }

                    this._startAudioVisualizer();

                    if (typeof onStart === 'function') onStart();
                };

                recognition.onresult = (event) => {
                    let transcript = '';
                    for (let i = event.resultIndex; i < event.results.length; i++) {
                        transcript += event.results[i][0].transcript;
                    }
                    if (transcript.trim()) {
                        textarea.value = initialText + transcript;
                        textarea.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                };

                recognition.onerror = (event) => {
                    console.warn('Voice Recognition Error:', event.error);
                    this.isListening = false;
                    this._stopAudioVisualizer();
                    this._resetUI(button, icon, indicator);

                    if (event.error === 'not-allowed' || event.error === 'service-not-allowed') {
                        notify('Microphone Access Denied. Please enable microphone permissions in your browser address bar.', 'error', 'Permission Required');
                    } else if (event.error !== 'no-speech' && event.error !== 'aborted') {
                        if (typeof onError === 'function') onError(event.error);
                    }
                };

                recognition.onend = () => {
                    this.isListening = false;
                    this._stopAudioVisualizer();
                    this._resetUI(button, icon, indicator);
                    if (typeof onEnd === 'function') onEnd();
                };

                this.activeRecognition = recognition;
                recognition.start();
            } catch(err) {
                console.error('Speech recognition exception:', err);
                this.isListening = false;
                this._stopAudioVisualizer();
                this._resetUI(button, icon, indicator);
                notify('Could not start microphone: ' + (err.message || 'Unknown error'), 'error', 'Microphone Error');
            }
        }

        stop(button, icon, indicator) {
            if (this.activeRecognition) {
                try { this.activeRecognition.stop(); } catch(e) {}
            }
            this.isListening = false;
            this._stopAudioVisualizer();
            this._resetUI(button, icon, indicator);
        }

        _startAudioVisualizer() {
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) return;

            navigator.mediaDevices.getUserMedia({ audio: true })
                .then((stream) => {
                    this.mediaStream = stream;
                    const AudioCtx = window.AudioContext || window.webkitAudioContext;
                    if (!AudioCtx) return;

                    this.audioCtx = new AudioCtx();
                    const source = this.audioCtx.createMediaStreamSource(stream);
                    this.analyser = this.audioCtx.createAnalyser();
                    this.analyser.fftSize = 64;
                    source.connect(this.analyser);

                    const dataArray = new Uint8Array(this.analyser.frequencyBinCount);

                    const updateBars = () => {
                        if (!this.isListening || !this.analyser) return;
                        this.analyser.getByteFrequencyData(dataArray);

                        const bars = document.querySelectorAll('#broad-wave-bars .broad-wave-bar');
                        if (bars.length > 0) {
                            for (let i = 0; i < bars.length; i++) {
                                const val = dataArray[i] || 0;
                                const height = Math.max(4, Math.min(26, (val / 255) * 28));
                                bars[i].style.animation = 'none';
                                bars[i].style.height = `${height}px`;
                            }
                        }
                        this.animFrameId = requestAnimationFrame(updateBars);
                    };
                    updateBars();
                })
                .catch((err) => {
                    console.log('Audio spectrum stream fallback to calm CSS animations:', err);
                });
        }

        _stopAudioVisualizer() {
            if (this.animFrameId) {
                cancelAnimationFrame(this.animFrameId);
                this.animFrameId = null;
            }
            if (this.mediaStream) {
                this.mediaStream.getTracks().forEach(track => track.stop());
                this.mediaStream = null;
            }
            if (this.audioCtx) {
                try { this.audioCtx.close(); } catch(e) {}
                this.audioCtx = null;
            }
            this.analyser = null;
        }

        _resetUI(button, icon, indicator) {
            if (button) button.classList.remove('listening');
            if (icon) {
                icon.setAttribute('name', 'mic-outline');
            }
            const broadContainer = document.getElementById('broad-voice-container');
            if (broadContainer) {
                broadContainer.style.display = 'none';
            }
            if (indicator && indicator.innerHTML.includes('Listening')) {
                indicator.innerHTML = '';
            }
        }
    }

    window.VoiceRecognitionEngine = VoiceRecognitionEngine;
})(window);
