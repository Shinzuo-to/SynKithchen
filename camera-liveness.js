(function () {
    "use strict";

    const visionModuleUrl =
        "https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@0.10.21/vision_bundle.mjs";

    const visionWasmUrl =
        "https://cdn.jsdelivr.net/npm/@mediapipe/tasks-vision@0.10.21/wasm";

    const faceLandmarkerUrl =
        "https://storage.googleapis.com/mediapipe-models/face_landmarker/face_landmarker/float16/1/face_landmarker.task";

    function getYaw(landmarks) {
        const leftEye = landmarks[33];
        const rightEye = landmarks[263];
        const nose = landmarks[1];
        const eyeWidth = Math.abs(rightEye.x - leftEye.x);

        if (eyeWidth === 0) {
            return null;
        }

        return (
            nose.x - (leftEye.x + rightEye.x) / 2
        ) / eyeWidth;
    }

    function getBlinkScores(result) {
        const categories =
            result.faceBlendshapes &&
            result.faceBlendshapes[0] &&
            result.faceBlendshapes[0].categories;

        if (!categories) {
            return null;
        }

        const scores = {};

        for (const category of categories) {
            if (
                category.categoryName === "eyeBlinkLeft" ||
                category.categoryName === "eyeBlinkRight"
            ) {
                scores[category.categoryName] =
                    category.score;
            }
        }

        if (
            scores.eyeBlinkLeft === undefined ||
            scores.eyeBlinkRight === undefined
        ) {
            return null;
        }

        return scores;
    }

    function cameraErrorMessage(error) {
        if (!window.isSecureContext) {
            return "Your browser blocks camera access on this address. Open the site with http://localhost in this browser, or configure HTTPS if you are using a network IP address.";
        }

        if (error.name === "NotAllowedError") {
            return "Camera permission was denied. Allow camera access for this site in your browser's address-bar or site settings, then reload.";
        }

        if (error.name === "NotFoundError") {
            return "No camera was found. Connect a webcam or camera, then reload.";
        }

        if (error.name === "NotReadableError") {
            return "The camera is busy or unavailable. Close other apps using it and try again.";
        }

        if (error.name === "SecurityError") {
            return "Camera access is blocked by the browser. Open the site through http://localhost or use HTTPS, then allow camera access in site settings.";
        }

        if (error.name === "OverconstrainedError") {
            return "The selected camera does not support the requested video settings. Choose another camera and try again.";
        }

        return error.message ||
            "Unable to start the selected camera.";
    }

    async function createFaceLandmarker() {
        const vision =
            await import(visionModuleUrl);

        const fileset =
            await vision.FilesetResolver.forVisionTasks(
                visionWasmUrl
            );

        return vision.FaceLandmarker.createFromOptions(
            fileset,
            {
                baseOptions: {
                    modelAssetPath: faceLandmarkerUrl
                },
                runningMode: "VIDEO",
                numFaces: 1,
                outputFaceBlendshapes: true
            }
        );
    }

    window.startCameraLiveness = function (options) {
        const video = options.video;
        const startButton = options.startButton;
        const cameraSelect = options.cameraSelect;
        const captureButton = options.captureButton;
        const instruction = options.instruction;
        const onReset = options.onReset || function () {};

        let stream = null;
        let landmarker = null;
        let selectedDeviceId = "";
        let phase = "center";
        let baseline = null;
        let centerReadings = [];
        let consecutiveFrames = 0;
        let blinkWasOpen = false;
        let blinkWasClosed = false;
        let lastVideoTime = -1;
        let stopped = false;
        let animationFrameId = null;
        let runId = 0;

        function setInstruction(text, isError) {
            instruction.textContent = text;
            instruction.classList.toggle(
                "liveness-error",
                Boolean(isError)
            );
        }

        function resetChallenge() {
            phase = "center";
            baseline = null;
            centerReadings = [];
            consecutiveFrames = 0;
            blinkWasOpen = false;
            blinkWasClosed = false;
            window.cameraLivenessComplete = false;
            captureButton.disabled = true;
            onReset();
            setInstruction(
                "Look straight at the camera and hold still."
            );
        }

        function stopStream() {
            if (stream) {
                for (const track of stream.getTracks()) {
                    track.stop();
                }

                stream = null;
            }
        }

        async function refreshCameraList(preferredDeviceId) {
            const devices =
                await navigator.mediaDevices.enumerateDevices();
            const cameras =
                devices.filter(
                    device => device.kind === "videoinput"
                );

            cameraSelect.replaceChildren();

            if (cameras.length === 0) {
                const option =
                    document.createElement("option");

                option.textContent = "No cameras found";
                option.disabled = true;
                option.selected = true;
                cameraSelect.appendChild(option);
                return;
            }

            cameras.forEach((camera, index) => {
                const option =
                    document.createElement("option");

                option.value = camera.deviceId;
                option.textContent =
                    camera.label ||
                    `Camera ${index + 1}`;
                cameraSelect.appendChild(option);
            });

            const desiredDeviceId =
                preferredDeviceId ||
                cameraSelect.value ||
                cameras[0].deviceId;

            cameraSelect.value =
                cameras.some(
                    camera => camera.deviceId === desiredDeviceId
                )
                    ? desiredDeviceId
                    : cameras[0].deviceId;
        }

        async function openCamera(deviceId, requestedRunId) {
            if (
                !window.isSecureContext ||
                !navigator.mediaDevices ||
                !navigator.mediaDevices.getUserMedia
            ) {
                throw new Error(
                    "Camera access requires a secure browser context. Open this site through http://localhost or use HTTPS."
                );
            }

            const videoConstraints = {
                width: { ideal: 640 },
                height: { ideal: 480 }
            };

            if (deviceId) {
                videoConstraints.deviceId = {
                    exact: deviceId
                };
            }

            let nextStream;

            try {
                nextStream =
                    await navigator.mediaDevices.getUserMedia({
                        video: videoConstraints,
                        audio: false
                    });
            } catch (error) {
                if (error.name !== "OverconstrainedError") {
                    throw error;
                }

                console.warn(
                    "The camera rejected the preferred video resolution; retrying with browser defaults.",
                    error
                );
                nextStream =
                    await navigator.mediaDevices.getUserMedia({
                        video: deviceId
                            ? { deviceId: { exact: deviceId } }
                            : true,
                        audio: false
                    });
            }

            if (stopped || requestedRunId !== runId) {
                for (const track of nextStream.getTracks()) {
                    track.stop();
                }
                return false;
            }

            const previousStream = stream;
            video.srcObject = nextStream;

            try {
                await video.play();
            } catch (error) {
                video.srcObject =
                    stopped || requestedRunId !== runId
                        ? null
                        : previousStream;
                for (const track of nextStream.getTracks()) {
                    track.stop();
                }
                throw error;
            }

            if (stopped || requestedRunId !== runId) {
                for (const track of nextStream.getTracks()) {
                    track.stop();
                }
                if (video.srcObject === nextStream) {
                    video.srcObject = null;
                }
                return false;
            }

            stream = nextStream;
            if (previousStream) {
                for (const track of previousStream.getTracks()) {
                    track.stop();
                }
            }

            const videoTrack =
                nextStream.getVideoTracks()[0];
            selectedDeviceId =
                videoTrack.getSettings().deviceId ||
                deviceId;

            try {
                await refreshCameraList(selectedDeviceId);
            } catch (error) {
                console.warn(
                    "Camera started, but the available camera list could not be loaded.",
                    error
                );
            }

            return true;
        }

        function processResult(result) {
            const faces = result.faceLandmarks;

            if (!faces || faces.length === 0) {
                consecutiveFrames = 0;
                setInstruction(
                    "Move into the frame so your face is clearly visible."
                );
                return;
            }

            const yaw = getYaw(faces[0]);

            if (yaw === null) {
                consecutiveFrames = 0;
                setInstruction(
                    "Keep your face centered and visible."
                );
                return;
            }

            if (phase === "center") {
                centerReadings.push(yaw);

                if (centerReadings.length >= 12) {
                    baseline =
                        centerReadings.reduce(
                            (sum, reading) => sum + reading,
                            0
                        ) / centerReadings.length;
                    phase = "left";
                    centerReadings = [];
                    setInstruction(
                        "Turn your head to your left."
                    );
                } else {
                    setInstruction(
                        "Look straight at the camera and hold still."
                    );
                }

                return;
            }

            if (phase === "left") {
                if (yaw - baseline > 0.11) {
                    consecutiveFrames++;
                } else {
                    consecutiveFrames = 0;
                }

                if (consecutiveFrames >= 3) {
                    phase = "centerAfterLeft";
                    consecutiveFrames = 0;
                    setInstruction(
                        "Good. Return your head to the center."
                    );
                }

                return;
            }

            if (phase === "centerAfterLeft") {
                if (Math.abs(yaw - baseline) < 0.06) {
                    consecutiveFrames++;
                } else {
                    consecutiveFrames = 0;
                }

                if (consecutiveFrames >= 3) {
                    phase = "right";
                    consecutiveFrames = 0;
                    setInstruction(
                        "Now turn your head to your right."
                    );
                }

                return;
            }

            if (phase === "right") {
                if (yaw - baseline < -0.11) {
                    consecutiveFrames++;
                } else {
                    consecutiveFrames = 0;
                }

                if (consecutiveFrames >= 3) {
                    phase = "blink";
                    consecutiveFrames = 0;
                    setInstruction(
                        "Return to the center, then blink once."
                    );
                }

                return;
            }

            if (phase === "blink") {
                const blinkScores =
                    getBlinkScores(result);

                if (!blinkScores) {
                    setInstruction(
                        "Blink detection is unavailable. Reload the page and try again.",
                        true
                    );
                    return;
                }

                const bothEyesOpen =
                    blinkScores.eyeBlinkLeft < 0.3 &&
                    blinkScores.eyeBlinkRight < 0.3;

                const bothEyesClosed =
                    blinkScores.eyeBlinkLeft > 0.55 &&
                    blinkScores.eyeBlinkRight > 0.55;

                if (bothEyesOpen) {
                    if (blinkWasClosed) {
                        phase = "complete";
                        window.cameraLivenessComplete = true;
                        captureButton.disabled = false;
                        setInstruction(
                            "Liveness check complete. Click Capture Face."
                        );
                        return;
                    }

                    blinkWasOpen = true;
                } else if (blinkWasOpen && bothEyesClosed) {
                    blinkWasClosed = true;
                }

                setInstruction(
                    "Return to the center, then blink once."
                );
                return;
            }

            if (phase === "complete") {
                setInstruction(
                    "Liveness check complete. Click Capture Face."
                );
            }
        }

        function processFrame(requestedRunId) {
            if (
                stopped ||
                requestedRunId !== runId ||
                phase === "complete"
            ) {
                animationFrameId = null;
                return;
            }

            if (
                landmarker &&
                video.readyState >= HTMLMediaElement.HAVE_CURRENT_DATA &&
                video.currentTime !== lastVideoTime
            ) {
                lastVideoTime = video.currentTime;

                try {
                    const result =
                        landmarker.detectForVideo(
                            video,
                            performance.now()
                        );

                    processResult(result);
                } catch (error) {
                    if (stopped || requestedRunId !== runId) {
                        return;
                    }
                    console.error(error);
                    stopped = true;
                    stopStream();
                    video.srcObject = null;
                    if (landmarker) {
                        landmarker.close();
                        landmarker = null;
                    }
                    resetChallenge();
                    startButton.hidden = false;
                    startButton.disabled = false;
                    startButton.textContent = "Start Camera";
                    setInstruction(
                        "Face tracking stopped unexpectedly. Click Start Camera to try again.",
                        true
                    );
                    return;
                }
            }

            if (phase !== "complete") {
                animationFrameId =
                    requestAnimationFrame(
                        () => processFrame(requestedRunId)
                    );
            } else {
                animationFrameId = null;
            }
        }

        function stop() {
            runId++;
            stopped = true;
            if (animationFrameId !== null) {
                cancelAnimationFrame(animationFrameId);
                animationFrameId = null;
            }
            stopStream();
            video.srcObject = null;
            if (landmarker) {
                landmarker.close();
                landmarker = null;
            }
            resetChallenge();
            startButton.hidden = false;
            startButton.disabled = false;
            startButton.textContent = "Start Camera";
        }

        cameraSelect.addEventListener(
            "change",
            async function () {
                if (!stream) {
                    return;
                }

                if (cameraSelect.value === selectedDeviceId) {
                    return;
                }

                const requestedRunId = runId;
                resetChallenge();
                setInstruction("Switching camera...");

                try {
                    const cameraStarted =
                        await openCamera(
                            cameraSelect.value,
                            requestedRunId
                        );

                    if (
                        !cameraStarted ||
                        stopped ||
                        requestedRunId !== runId
                    ) {
                        return;
                    }

                    resetChallenge();
                    if (animationFrameId === null) {
                        animationFrameId =
                            requestAnimationFrame(
                                () => processFrame(requestedRunId)
                            );
                    }
                } catch (error) {
                    if (stopped || requestedRunId !== runId) {
                        return;
                    }
                    console.error(error);
                    cameraSelect.value = selectedDeviceId;
                    setInstruction(
                        cameraErrorMessage(error),
                        true
                    );
                }
            }
        );

        window.cameraLivenessComplete = false;
        captureButton.disabled = true;
        setInstruction(
            "Click Start Camera and allow access when your browser asks."
        );

        refreshCameraList().catch(error => {
            console.warn(
                "The camera list could not be loaded before permission was granted.",
                error
            );
            setInstruction(
                "Camera choices are not available yet. Click Start Camera to allow access."
            );
        });

        if (
            navigator.mediaDevices &&
            navigator.mediaDevices.addEventListener
        ) {
            navigator.mediaDevices.addEventListener(
                "devicechange",
                function () {
                    refreshCameraList(selectedDeviceId || cameraSelect.value)
                        .catch(error => {
                            console.warn(
                                "The available camera list could not be refreshed.",
                                error
                            );
                        });
                }
            );
        }

        startButton.addEventListener("click", async function () {
            const requestedRunId = ++runId;
            startButton.disabled = true;
            startButton.textContent = "Starting Camera...";
            stopped = false;
            setInstruction(
                "Waiting for camera permission. Allow access in your browser if prompted."
            );

            try {
                const cameraStarted =
                    await openCamera(
                        cameraSelect.value,
                        requestedRunId
                    );

                if (
                    !cameraStarted ||
                    stopped ||
                    requestedRunId !== runId
                ) {
                    return;
                }

                setInstruction(
                    "Loading face and blink detection. Keep your face in view..."
                );
                const nextLandmarker =
                    await createFaceLandmarker();

                if (stopped || requestedRunId !== runId) {
                    nextLandmarker.close();
                    return;
                }

                landmarker = nextLandmarker;
                startButton.hidden = true;
                resetChallenge();
                animationFrameId =
                    requestAnimationFrame(
                        () => processFrame(requestedRunId)
                    );
            } catch (error) {
                console.error(error);
                startButton.disabled = false;
                startButton.textContent = "Start Camera";
                setInstruction(
                    stream
                        ? "Camera is on, but face/liveness detection could not load. Check that this browser can access cdn.jsdelivr.net and storage.googleapis.com, then try again."
                        : cameraErrorMessage(error),
                    true
                );
            }
        });

        window.addEventListener("beforeunload", function () {
            stop();
        });

        return { stop: stop };
    };
})();