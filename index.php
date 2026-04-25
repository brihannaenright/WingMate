<?php
declare(strict_types=1);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WingMate</title>

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"
    integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">

    <!-- Global CSS -->
    <link rel="stylesheet" href="/assets/global.css">
</head>

<body>

<style>
    .intro-section {
        background: #C30E59;
        min-height: 60vh;
    }

    .intro-section p {
        color: white;
        font-weight: 400;
    }

    .intro-tagline {
        font-size: 24px;
    }

    .intro-subtitle {
        font-size: 18px;
        max-width: 600px;
    }

    .section-title {
        font-weight: 700;
    }

    .fs-card-heading {
        font-size: 20px;
        font-weight: 600;
    }

    .fs-body {
        font-size: 16px;
    }

    .button-tertiary {
        color: white;
        font-weight: 300;
        font-size: 14px;
        border-radius: var(--wm-button-radius);
        width: auto;
        height: auto;
        border: 1px solid white;
        cursor: pointer;
        background: transparent;
        white-space: nowrap;
    }

    .button-primary,
    .button-secondary,
    .button-tertiary{
        transition: all 0.3s ease;
    }

    .button-primary:hover,
    .button-secondary:hover,
    .button-tertiary:hover {
        transform: translateY(-2px);
    }

    .step-card {
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
        transition: all 0.3s ease;
    }

    .step-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 25px rgba(195, 14, 89, 0.15);
    }

    .step-number {
        width: 50px;
        height: 50px;
        background: #C30E59;
        font-size: 24px;
    }

    .feature-card {
        transition: all 0.3s ease;
    }

    .feature-card:hover {
        background: #FFF0F6;
        transform: translateY(-3px);
    }

    .feature-icon {
        font-size: 48px;
    }

    /* FAQ Section */
    .faq-item {
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    }

    .faq-question {
        transition: all 0.3s ease;
        cursor: pointer;
        color: #666;
    }

    .faq-question:hover {
        background: #FFF0F6;
        color: #C30E59;
    }

    .faq-toggle {
        font-size: 24px;
        color: #C30E59;
        transition: transform 0.3s ease;
    }

    .faq-item.active .faq-toggle {
        transform: rotate(180deg);
    }

    .faq-answer {
        max-height: 0;
        overflow: hidden;
        transition: all 0.3s ease;
    }

    .answer-p {
        font-size: 15px;
        font-weight: 400;
        line-height: 1.6;
    }

    .faq-item.active .faq-answer {
        padding: 1.25rem;
        max-height: 500px;
    }

    .final-section {
        background: #F2AE66;
    }

    .faq-container {
        max-width: 800px;
    }
</style>

<div class="entry-page d-flex flex-column">
    <nav class="navbar navbar-expand-lg navbar-light wingmate-navbar sticky-top">
        <div class="container-fluid">
            <div class="navbar-brand">
                <img src="/assets/images/wingmate-navbar.png" alt="WingMate" class="navbar-logo" style="height: 50px; width: auto;">
            </div>

            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav"
                    aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/features/auth/register.php') !== false ? 'active' : ''; ?>" href="/features/auth/register.php">Register</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo strpos($_SERVER['REQUEST_URI'], '/features/auth/login.php') !== false ? 'active' : ''; ?>" href="/features/auth/login.php">Login</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <section class="intro-section d-flex flex-column justify-content-center align-items-center text-center text-white py-5">
        <h1 class="section-title mb-3">Dating, Reimagined</h1>
        <p class="intro-tagline mb-2">Stop mindless swiping. Start getting matched by your friends.</p>
        <p class="intro-subtitle mb-5">
            The dating app where your friends know you best. They vote on your matches because who understands your type better than they do?
        </p>
        <div class="d-flex gap-3 justify-content-center flex-wrap">
            <button type="button" class="button-secondary" onclick="window.location.href='/features/auth/register.php'">Join Now</button>
            <button type="button" class="button-tertiary" onclick="window.location.href='/features/auth/login.php'">Already a Member?</button>
        </div>
    </section>

    <!-- How It Works -->
    <section class="py-5 bg-light">
        <h2 class="section-title text-center mb-5">How WingMate Works</h2>
        <div class="container-xl px-4">
            <div class="row g-4">
                <div class="col-md-6 col-lg-4">
                    <div class="step-card bg-white text-center py-5 px-4 rounded">
                        <div class="step-number d-flex align-items-center justify-content-center rounded-circle text-white fw-bold mx-auto mb-3">1</div>
                        <h3 class="fs-card-heading mb-3">Build Your Squad</h3>
                        <p class="fs-body text-secondary m-0">Create your profile and add your trusted friends to your WingMate squad. The people who know you best.</p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="step-card bg-white text-center py-5 px-4 rounded">
                        <div class="step-number d-flex align-items-center justify-content-center rounded-circle text-white fw-bold mx-auto mb-3">2</div>
                        <h3 class="fs-card-heading mb-3">You Decide Who Matters</h3>
                        <p class="fs-body text-secondary m-0">Swipe through potential matches. Your choices matter because you're thinking about what your friends will think.</p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="step-card bg-white text-center py-5 px-4 rounded">
                        <div class="step-number d-flex align-items-center justify-content-center rounded-circle text-white fw-bold mx-auto mb-3">3</div>
                        <h3 class="fs-card-heading mb-3">Friends Vote</h3>
                        <p class="fs-body text-secondary m-0">Your squad votes on your matches. They see your potential partners and decide if they're a good fit for you.</p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="step-card bg-white text-center py-5 px-4 rounded">
                        <div class="step-number d-flex align-items-center justify-content-center rounded-circle text-white fw-bold mx-auto mb-3">4</div>
                        <h3 class="fs-card-heading mb-3">Get Matched</h3>
                        <p class="fs-body text-secondary m-0">Only approved matches unlock the ability to chat. This means you're chatting with people your friends genuinely think you'd vibe with.</p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="step-card bg-white text-center py-5 px-4 rounded">
                        <div class="step-number d-flex align-items-center justify-content-center rounded-circle text-white fw-bold mx-auto mb-3">5</div>
                        <h3 class="fs-card-heading mb-3">Chat & Connect</h3>
                        <p class="fs-body text-secondary m-0">Message your matches directly or discuss potential dates with your friends in group chats.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Features -->
    <section class="py-5 bg-white">
        <h2 class="section-title text-center mb-5">Key Features</h2>
        <div class="container-xl px-4">
            <div class="row g-4">
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card text-center bg-light rounded p-4">
                        <div class="feature-icon d-inline-block mb-3">👥</div>
                        <h4 class="fs-card-heading mb-2">Friend-Powered Matching</h4>
                        <p class="fs-body text-secondary m-0">Your friends vote on your matches, so you only connect with people who truly align with your personality and values.</p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card text-center bg-light rounded p-4">
                        <div class="feature-icon d-inline-block mb-3">💬</div>
                        <h4 class="fs-card-heading mb-2">Direct Messaging</h4>
                        <p class="fs-body text-secondary m-0">Chat with matches you've connected with and discuss dating with your friends in private or group conversations.</p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card text-center bg-light rounded p-4">
                        <div class="feature-icon d-inline-block mb-3">⭐</div>
                        <h4 class="fs-card-heading mb-2">Thoughtful Swiping</h4>
                        <p class="fs-body text-secondary m-0">The knowledge that your friends will judge your choices makes you swipe more thoughtfully and authentically.</p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card text-center bg-light rounded p-4">
                        <div class="feature-icon d-inline-block mb-3">🛡️</div>
                        <h4 class="fs-card-heading mb-2">Safe & Secure</h4>
                        <p class="fs-body text-secondary m-0">Enterprise-level security with encrypted messaging, verified profiles, and safe voting processes.</p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card text-center bg-light rounded p-4">
                        <div class="feature-icon d-inline-block mb-3">🎯</div>
                        <h4 class="fs-card-heading mb-2">Quality Over Quantity</h4>
                        <p class="fs-body text-secondary m-0">Say goodbye to endless swiping. Focus on matches that your trusted circle believes in.</p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-4">
                    <div class="feature-card text-center bg-light rounded p-4">
                        <div class="feature-icon d-inline-block mb-3">📱</div>
                        <h4 class="fs-card-heading mb-2">Notifications</h4>
                        <p class="fs-body text-secondary m-0">Stay updated with real-time notifications about matches, friend votes, and messages.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- FAQ Section -->
    <section class="py-5 bg-light">
        <h2 class="section-title text-center mb-5">Frequently Asked Questions</h2>
        <div class="faq-container mx-auto">
            <div class="faq-item rounded mb-3">
                <div class="faq-question d-flex justify-content-between align-items-center bg-white fw-semibold p-3">
                    <span>Why do my friends need to vote on my matches?</span>
                    <span class="faq-toggle">▼</span>
                </div>
                <div class="faq-answer bg-light">
                    <p class="answer-p m-0">Your friends know you best! They understand your personality, values, and what you're truly looking for. By involving them in the matching process, you get matches that genuinely align with who you are. Plus, it takes the pressure off you to make every decision alone and makes dating more fun with your squad!</p>
                </div>
            </div>

            <div class="faq-item rounded mb-3">
                <div class="faq-question d-flex justify-content-between align-items-center bg-white fw-semibold p-3">
                    <span>Can I chat with someone my friends didn't approve?</span>
                    <span class="faq-toggle">▼</span>
                </div>
                <div class="faq-answer bg-light">
                    <p class="answer-p m-0">No. Messaging is only unlocked for matches that your friends have approved. This ensures that everyone you connect with has been vetted by people who truly know and care about you. This also discourages mindless swiping—you'll be more intentional knowing your friends will see your choices.</p>
                </div>
            </div>

            <div class="faq-item rounded mb-3">
                <div class="faq-question d-flex justify-content-between align-items-center bg-white fw-semibold p-3">
                    <span>Is my privacy protected? Will everything I do be visible?</span>
                    <span class="faq-toggle">▼</span>
                </div>
                <div class="faq-answer bg-light">
                    <p class="answer-p m-0">Yes! Your privacy is our priority. Your friends only see profiles you've swiped on—not your personal information, messages, or match history. All messages between you and your matches are private and encrypted. You control who's in your squad and what they can see.</p>
                </div>
            </div>

            <div class="faq-item rounded mb-3">
                <div class="faq-question d-flex justify-content-between align-items-center bg-white fw-semibold p-3">
                    <span>What if I don't want my friends judging my choices?</span>
                    <span class="faq-toggle">▼</span>
                </div>
                <div class="faq-answer bg-light">
                    <p class="answer-p m-0">That's the whole point! WingMate is designed for people who want their friends' input. If that's not for you, traditional dating apps might be a better fit. But if you like the idea of making more thoughtful choices with your squad's support, you'll love WingMate.</p>
                </div>
            </div>

            <div class="faq-item rounded mb-3">
                <div class="faq-question d-flex justify-content-between align-items-center bg-white fw-semibold p-3">
                    <span>Can I change my squad members?</span>
                    <span class="faq-toggle">▼</span>
                </div>
                <div class="faq-answer bg-light">
                    <p class="answer-p m-0">Absolutely! You can add or remove friends from your squad at any time. Your squad can be as small or as large as you want—just make sure they're people you trust to help you find your perfect match.</p>
                </div>
            </div>

            <div class="faq-item rounded mb-3">
                <div class="faq-question d-flex justify-content-between align-items-center bg-white fw-semibold p-3">
                    <span>Is WingMate free to use?</span>
                    <span class="faq-toggle">▼</span>
                </div>
                <div class="faq-answer bg-light">
                    <p class="answer-p m-0">Yes! Creating an account and using WingMate is completely free. Join your friends and start finding better matches today.</p>
                </div>
            </div>
        </div>
    </section>

    <section class="final-section text-center text-white py-5">
        <h2 class="section-title mb-3">Ready to Meet Your Match (With Your Squad's Approval)?</h2>
        <p class="fs-body mb-4 text-white fw-light">Join WingMate today and experience dating the way it's meant to be—with your best friends by your side.</p>
        <div class="d-flex gap-3 justify-content-center flex-wrap">
            <button type="button" class="button-primary" onclick="window.location.href='/features/auth/register.php'">Sign Up Free</button>
        </div>
    </section>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB"
        crossorigin="anonymous"></script>

<script>

    document.addEventListener('DOMContentLoaded', function() {
        // FAQ Toggle Functionality
        document.querySelectorAll('.faq-question').forEach(question => {
            question.addEventListener('click', function() {
                const faqItem = this.closest('.faq-item');
                faqItem.classList.toggle('active');
            });
        });
    });
</script>
</body>
</html>